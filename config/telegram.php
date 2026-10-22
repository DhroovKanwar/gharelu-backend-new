<?php

return [

    // Shop alerts through a Telegram bot (free, no DLT). Create the bot with @BotFather,
    // message it once from each phone that should get alerts, then list the chat ids.
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),

    // Comma-separated chat ids (a person, or a group the bot was added to).
    'chat_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('TELEGRAM_CHAT_IDS', ''))))),

];
