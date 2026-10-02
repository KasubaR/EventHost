<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Astragate sandbox test</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 520px; margin: 40px auto; padding: 0 16px; color: #1a1a1a; }
        label { display: block; margin: 14px 0 4px; font-weight: 600; }
        input { width: 100%; padding: 10px; border: 1px solid #bbb; border-radius: 6px; box-sizing: border-box; }
        button { margin-top: 16px; padding: 10px 18px; border: 0; border-radius: 6px; background: #1a1a1a; color: #fff; cursor: pointer; }
        .box { margin-top: 24px; padding: 14px; border: 1px solid #ddd; border-radius: 8px; background: #fafafa; }
        .err { color: #b00020; }
        pre { white-space: pre-wrap; word-break: break-word; font-size: 12px; }
        .pill { display: inline-block; padding: 2px 10px; border-radius: 99px; background: #eee; font-weight: 600; }
    </style>
</head>
<body>
    <h1>Astragate sandbox test</h1>
    <p>Internal page. Creates no payments and grants no credits. Amounts are capped at K{{ $maxAmount }}.</p>

    @unless ($configured)
        <p class="err">Astragate client ID / secret are not set in the environment.</p>
    @endunless

    @if (session('error'))
        <p class="err">{{ session('error') }}</p>
    @endif
    @if ($errors->any())
        <p class="err">{{ $errors->first() }}</p>
    @endif

    <form method="POST" action="/{{ $path }}">
        @csrf
        <label for="phone">Mobile money number</label>
        <input id="phone" name="phone" value="{{ old('phone') }}" placeholder="0971234567" required>
        <label for="amount">Amount (ZMW)</label>
        <input id="amount" name="amount" type="number" step="0.01" min="1" max="{{ $maxAmount }}" value="{{ old('amount', 1) }}" required>
        <button type="submit">Send test collection</button>
    </form>

    @if ($record)
        <div class="box">
            <p>Reference: <code>{{ $reference }}</code></p>
            <p>Status: <span class="pill">{{ $record['status'] }}</span></p>
            <form method="POST" action="/{{ $path }}/check/{{ $reference }}">
                @csrf
                <button type="submit">Check status with Astragate</button>
            </form>
            <p>Initiate response:</p>
            <pre>{{ json_encode($record['initiated'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            <p>Callback received:</p>
            <pre>{{ $record['callback'] ? json_encode($record['callback'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'Not yet' }}</pre>
            @isset($record['checked'])
                <p>Last status check:</p>
                <pre>{{ json_encode($record['checked'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            @endisset
        </div>
        <p><a href="/{{ $path }}?ref={{ $reference }}">Refresh</a></p>
    @elseif ($reference !== '')
        <p class="err">Unknown or expired reference.</p>
    @endif
</body>
</html>
