<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Dumps the whole database to a gzipped .sql file and uploads it to Cloudflare R2
 * (or any S3-compatible bucket).
 *
 * The file name is the weekday (backup-mon.sql.gz ... backup-sun.sql.gz), so every run
 * replaces last week's file for that day: the bucket always holds the last 7 days and
 * never grows. No mysqldump binary and no extra composer package are needed.
 *
 * Needs env: R2_ENDPOINT, R2_BUCKET, R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY.
 * Use --no-upload to only write the file locally (storage/app/backups) for a test.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--no-upload : Only write the dump to storage/app/backups, do not upload}';

    protected $description = 'Dump the database to a gzipped SQL file and upload it to Cloudflare R2 (weekday-named, 7-day rotation).';

    /** Tables that only hold throw-away data. */
    private const SKIP_DATA = ['cache', 'cache_locks', 'sessions', 'job_batches', 'jobs', 'failed_jobs'];

    public function handle(): int
    {
        try {
            $sql = $this->dump();
        } catch (Throwable $e) {
            $this->error('Dump failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $gz = gzencode($sql, 9);
        $name = 'backup-'.strtolower(now()->format('D')).'.sql.gz';
        $this->info(sprintf('Dump ready: %s (%s KB gzipped)', $name, number_format(strlen($gz) / 1024, 1)));

        if ($this->option('no-upload')) {
            $dir = storage_path('app/backups');
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($dir.'/'.$name, $gz);
            $this->info('Saved locally: '.$dir.'/'.$name);

            return self::SUCCESS;
        }

        try {
            $this->upload($name, $gz);
        } catch (Throwable $e) {
            $this->error('Upload failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Uploaded to R2 bucket '.env('R2_BUCKET').' as '.$name);

        return self::SUCCESS;
    }

    private function dump(): string
    {
        $pdo = DB::connection()->getPdo();
        $database = DB::connection()->getDatabaseName();

        $out = "-- Gharelu Bake backup of `{$database}` at ".now()->toDateTimeString()." UTC\n";
        $out .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET time_zone='+00:00';\n\n";

        $tables = array_map(fn ($r) => array_values((array) $r)[0], DB::select('SHOW TABLES'));
        sort($tables);

        foreach ($tables as $table) {
            $create = (array) DB::selectOne("SHOW CREATE TABLE `{$table}`");
            $out .= "DROP TABLE IF EXISTS `{$table}`;\n".($create['Create Table'] ?? array_values($create)[1]).";\n\n";

            if (in_array($table, self::SKIP_DATA, true)) {
                continue;
            }

            $columns = null;
            DB::table($table)->orderByRaw('1')->chunk(500, function ($rows) use (&$out, $table, $pdo, &$columns) {
                foreach ($rows as $row) {
                    $row = (array) $row;
                    $columns ??= '`'.implode('`,`', array_keys($row)).'`';
                    $values = array_map(
                        fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)),
                        array_values($row),
                    );
                    $out .= "INSERT INTO `{$table}` ({$columns}) VALUES (".implode(',', $values).");\n";
                }
            });
            $out .= "\n";
        }

        return $out."SET FOREIGN_KEY_CHECKS=1;\n";
    }

    /** PUT the object with an AWS Signature V4 (R2 region is "auto"). */
    private function upload(string $name, string $body): void
    {
        $endpoint = rtrim((string) env('R2_ENDPOINT'), '/');
        $bucket = (string) env('R2_BUCKET');
        $accessKey = (string) env('R2_ACCESS_KEY_ID');
        $secret = (string) env('R2_SECRET_ACCESS_KEY');

        if ($endpoint === '' || $bucket === '' || $accessKey === '' || $secret === '') {
            throw new \RuntimeException('Set R2_ENDPOINT, R2_BUCKET, R2_ACCESS_KEY_ID and R2_SECRET_ACCESS_KEY first.');
        }

        $host = parse_url($endpoint, PHP_URL_HOST);
        $amzDate = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $payloadHash = hash('sha256', $body);
        $uri = '/'.$bucket.'/'.$name;

        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = "PUT\n{$uri}\n\n"
            ."content-type:application/gzip\nhost:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzDate}\n\n"
            ."{$signedHeaders}\n{$payloadHash}";

        $scope = "{$date}/auto/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n".hash('sha256', $canonicalRequest);

        $key = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', 's3',
                hash_hmac('sha256', 'auto',
                    hash_hmac('sha256', $date, 'AWS4'.$secret, true), true), true), true);
        $signature = hash_hmac('sha256', $stringToSign, $key);

        $response = Http::withHeaders([
            'x-amz-date' => $amzDate,
            'x-amz-content-sha256' => $payloadHash,
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
        ])->withBody($body, 'application/gzip')->timeout(60)->put($endpoint.$uri);

        if (! $response->successful()) {
            throw new \RuntimeException('HTTP '.$response->status().' '.substr($response->body(), 0, 300));
        }
    }
}
