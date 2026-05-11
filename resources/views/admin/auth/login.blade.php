<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Admin Sign In — {{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            background: #0f1117;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'DM Sans', sans-serif;
            color: #e2e8f0;
            padding: 24px;
        }

        .al-wrap {
            width: 100%;
            max-width: 420px;
        }

        .al-logo {
            text-align: center;
            margin-bottom: 32px;
        }

        .al-logo-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 52px;
            height: 52px;
            background: rgba(108,92,231,.15);
            border: 1px solid rgba(108,92,231,.3);
            border-radius: 14px;
            margin-bottom: 14px;
            color: #a78bfa;
            font-size: 1.4rem;
        }

        .al-logo h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.35rem;
            font-weight: 700;
            color: #f1f5f9;
            letter-spacing: -.01em;
        }

        .al-logo p {
            font-size: .8rem;
            color: #64748b;
            margin-top: 4px;
            letter-spacing: .04em;
            text-transform: uppercase;
            font-weight: 500;
        }

        .al-card {
            background: #1a1d27;
            border: 1px solid #2a2d3a;
            border-radius: 16px;
            padding: 36px 32px;
        }

        .al-card-title {
            font-size: 1.05rem;
            font-weight: 600;
            color: #f1f5f9;
            margin-bottom: 24px;
        }

        .al-alert {
            background: rgba(239,68,68,.1);
            border: 1px solid rgba(239,68,68,.25);
            color: #fca5a5;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: .85rem;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .al-status {
            background: rgba(72,199,142,.1);
            border: 1px solid rgba(72,199,142,.25);
            color: #6ee7b7;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: .85rem;
            margin-bottom: 18px;
        }

        .al-field { margin-bottom: 18px; }

        .al-label {
            display: block;
            font-size: .8rem;
            font-weight: 600;
            color: #94a3b8;
            margin-bottom: 7px;
            letter-spacing: .02em;
            text-transform: uppercase;
        }

        .al-input {
            width: 100%;
            background: #0f1117;
            border: 1px solid #2a2d3a;
            border-radius: 8px;
            padding: 11px 14px;
            color: #f1f5f9;
            font-size: .92rem;
            font-family: inherit;
            transition: border-color .2s;
            outline: none;
        }

        .al-input::placeholder { color: #475569; }
        .al-input:focus { border-color: #6c5ce7; }
        .al-input--error { border-color: #ef4444 !important; }

        .al-input-wrap { position: relative; }
        .al-input-wrap .al-input { padding-right: 42px; }

        .al-eye {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #475569;
            cursor: pointer;
            padding: 4px;
            font-size: .9rem;
            line-height: 1;
        }
        .al-eye:hover { color: #94a3b8; }

        .al-error {
            display: block;
            color: #fca5a5;
            font-size: .78rem;
            margin-top: 5px;
        }

        .al-remember {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
            font-size: .85rem;
            color: #64748b;
            cursor: pointer;
        }

        .al-remember input[type="checkbox"] {
            accent-color: #6c5ce7;
            width: 15px;
            height: 15px;
            cursor: pointer;
        }

        .al-btn {
            width: 100%;
            background: #6c5ce7;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 12px;
            font-size: .95rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background .2s;
        }

        .al-btn:hover { background: #5b4bd4; }

        .al-footer {
            text-align: center;
            margin-top: 20px;
            font-size: .8rem;
            color: #475569;
        }

        .al-footer a {
            color: #7c6ef0;
            text-decoration: none;
        }

        .al-footer a:hover { text-decoration: underline; }

        .al-divider {
            border: none;
            border-top: 1px solid #2a2d3a;
            margin: 24px 0;
        }

        .al-restricted {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .78rem;
            color: #475569;
            justify-content: center;
        }

        .al-restricted i { color: #6c5ce7; }
    </style>
</head>
<body>

<div class="al-wrap">

    <div class="al-logo">
        <div class="al-logo-icon"><i class="fa-solid fa-shield-halved"></i></div>
        <h1>{{ config('app.name') }}</h1>
        <p>Admin Portal</p>
    </div>

    <div class="al-card">

        <p class="al-card-title">Sign in to continue</p>

        @if (session('status'))
            <div class="al-status"><i class="fa-solid fa-circle-check"></i> {{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="al-alert"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ url('admin/login') }}">
            @csrf

            <div class="al-field">
                <label for="email" class="al-label">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}"
                       class="al-input {{ $errors->has('email') ? 'al-input--error' : '' }}"
                       placeholder="admin@example.com" required autofocus autocomplete="username">
            </div>

            <div class="al-field">
                <label for="password" class="al-label">Password</label>
                <div class="al-input-wrap">
                    <input id="password" type="password" name="password"
                           class="al-input {{ $errors->has('password') ? 'al-input--error' : '' }}"
                           placeholder="••••••••" required autocomplete="current-password">
                    <button type="button" class="al-eye" id="togglePw" aria-label="Toggle password">
                        <i class="fa-solid fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <label class="al-remember">
                <input type="checkbox" name="remember"> Keep me signed in
            </label>

            <button type="submit" class="al-btn">
                <i class="fa-solid fa-arrow-right-to-bracket"></i> Sign In
            </button>

        </form>

        <hr class="al-divider">

        <div class="al-restricted">
            <i class="fa-solid fa-lock"></i> Restricted access — authorised personnel only
        </div>

    </div>

    <p class="al-footer">
        Not an admin? <a href="{{ route('login') }}">Go to user login</a>
    </p>

</div>

<script>
    document.getElementById('togglePw').addEventListener('click', function () {
        const input = document.getElementById('password');
        const icon  = document.getElementById('eyeIcon');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    });
</script>

</body>
</html>
