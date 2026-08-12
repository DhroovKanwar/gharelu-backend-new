# Gharelu Bake — Laravel Backend (starter scaffold)

**Important**: This code was written by hand and has **not been run or tested** — I don't have PHP/Composer or network access to Packagist in this environment, so I couldn't `composer install`, migrate, or hit these endpoints to verify them the way I could test every piece of the React frontend. Treat this as a strong, carefully-written starting point, not a verified-working deliverable.

**If you want this actually built, run, and tested end-to-end, Claude Code is the right tool** — it runs on your own machine where PHP/Composer/MySQL are real, so I can install Laravel, run migrations, start the server, and hit every endpoint to confirm it works before handing it back to you.

## What's included here

- `database/migrations/` — all 8 tables from the design doc
- `app/Models/` — Product, Category, GalleryImage, Order, OrderItem, CustomCakeRequest, ContactEnquiry, NewsletterSubscriber
- `app/Http/Requests/` — validation matching `src/utils/validators.js` exactly
- `app/Http/Controllers/Api/` — Products, Categories, Gallery, Orders, Payments (the critical price-trust fix), CustomCakeRequest, Leads
- `routes/api.php` — everything wired up with rate limiting

## What's NOT included yet (next batch, if you want it)

- Filament admin panel resources (ProductResource, OrderResource, dashboard widgets)
- Cloudinary signed-upload endpoint
- Sanctum auth setup / admin user seeding
- Webhook signature secret setup walkthrough

## How to actually stand this up

```bash
composer create-project laravel/laravel gharelu-bake-backend
cd gharelu-bake-backend

composer require razorpay/razorpay
composer require filament/filament:"^3.0" -W
composer require cloudinary-labs/cloudinary-laravel

# copy the files from this scaffold into place:
#   database/migrations/*.php  → your project's database/migrations/
#   app/Models/*.php           → app/Models/
#   app/Http/Requests/*.php    → app/Http/Requests/
#   app/Http/Controllers/Api/*.php → app/Http/Controllers/Api/
#   routes/api.php             → routes/api.php (merge, don't overwrite)
```

Then add to `.env`:
```
RAZORPAY_KEY=rzp_test_xxxxxxxxxxxx
RAZORPAY_SECRET=your_test_secret
RAZORPAY_WEBHOOK_SECRET=your_webhook_secret

CLOUDINARY_URL=cloudinary://api_key:api_secret@cloud_name
```

And add to `config/services.php`:
```php
'razorpay' => [
    'key' => env('RAZORPAY_KEY'),
    'secret' => env('RAZORPAY_SECRET'),
    'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
],
```

Then:
```bash
php artisan migrate
php artisan filament:install --panels
php artisan serve
```

At that point I'd strongly recommend going through Claude Code to actually verify every endpoint responds correctly, run the migrations against a real DB, and catch anything that doesn't work exactly as written here — this scaffold is my best hand-written attempt without the ability to execute or test it.
