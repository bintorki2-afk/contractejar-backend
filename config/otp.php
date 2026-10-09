<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OTP / SMS delivery driver
    |--------------------------------------------------------------------------
    |
    | Supported: "log", "taqnyat", "twilio".
    |
    | - log     : No real SMS is sent. The OTP message (including the plain code)
    |             is written to the Laravel log AND stored in the sms_logs table,
    |             so a developer can log in on a fresh install with no SMS account.
    | - taqnyat : Real SMS via Taqnyat (requires TAQNYAT_BEARER).
    | - twilio  : Real SMS via Twilio (requires TWILIO_SID / TWILIO_TOKEN / TWILIO_PHONE).
    |
    | When unset, it defaults to "log" outside production and "taqnyat" in
    | production. If a real driver is selected but its credentials are missing,
    | the sender safely falls back to "log" so the flow never breaks.
    |
    */
    'driver' => env('OTP_DRIVER'),

    /*
    |--------------------------------------------------------------------------
    | OTP length
    |--------------------------------------------------------------------------
    |
    | Keep 4 digits so existing mobile/web input fields stay compatible.
    | Online brute force is limited by max_attempts, lockout, and rate limits.
    |
    */
    'length' => (int) env('OTP_LENGTH', 4),

    /*
    |--------------------------------------------------------------------------
    | Fixed review code (TEMPORARY — testing only)
    |--------------------------------------------------------------------------
    |
    | When OTP_FIXED_CODE is set (e.g. "1111"), every issued OTP equals this
    | value so the app can be reviewed without SMS delivery. Leave it EMPTY in
    | production once SMS is live. The code is still hashed/verified/consumed
    | and rate-limited exactly like a random one.
    |
    */
    'fixed_code' => env('OTP_FIXED_CODE'),

    /*
    |--------------------------------------------------------------------------
    | Store-review number (دفعة د — ب4)
    |--------------------------------------------------------------------------
    |
    | OTP_REVIEW_MOBILE (e.g. 0597500013) always receives OTP_REVIEW_CODE — in
    | every environment INCLUDING production — and ONLY that number. All other
    | numbers get a random code. Same hashing, attempts, lockout and send limits.
    |
    */
    'review_mobile' => env('OTP_REVIEW_MOBILE'),

    'review_code' => env('OTP_REVIEW_CODE'),

    'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 10),

    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),

    'lock_minutes' => (int) env('OTP_LOCK_MINUTES', 15),

    /*
    | Minimum seconds between sending a new OTP to the same mobile.
    */
    'send_cooldown_seconds' => (int) env('OTP_SEND_COOLDOWN_SECONDS', 120),

    /*
    | Maximum OTP sends per mobile per hour (across verification + reset).
    */
    'send_max_per_hour' => (int) env('OTP_SEND_MAX_PER_HOUR', 5),

    /*
    | HTTP rate limiters (IP + mobile). Applied on OTP routes.
    */
    'send_http_max' => (int) env('OTP_SEND_HTTP_MAX', 5),

    // حد إجمالي لإرسال OTP لكل IP في الساعة (بغض النظر عن الرقم) — ضد استنزاف رصيد الرسائل.
    'send_http_ip_max_per_hour' => (int) env('OTP_SEND_HTTP_IP_MAX_PER_HOUR', 15),

    'send_http_decay_minutes' => (int) env('OTP_SEND_HTTP_DECAY_MINUTES', 10),

    'verify_http_max' => (int) env('OTP_VERIFY_HTTP_MAX', 10),

    'verify_http_decay_minutes' => (int) env('OTP_VERIFY_HTTP_DECAY_MINUTES', 1),

];
