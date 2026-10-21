<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $title }}</title>
<style>
    body { margin:0; font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background:#f6f5fb; color:#1f2937; display:grid; min-height:100vh; place-items:center; padding:16px; }
    main { background:#fff; border-radius:16px; padding:32px; max-width:420px; width:100%; box-shadow:0 10px 40px rgba(20,10,90,.08); }
    h1 { font-size:20px; margin:0 0 8px; }
    p { line-height:1.55; color:#4b5563; margin:8px 0 0; }
    button { margin-top:20px; background:{{ $accent }}; color:#fff; border:0; border-radius:10px; padding:11px 20px; font-size:15px; font-weight:600; cursor:pointer; }
    .store { font-size:13px; color:#6b7280; margin-bottom:16px; }
</style>
</head>
<body>
<main>
    <div class="store">{{ $store }}</div>
    <h1>{{ $title }}</h1>
    <p>{{ $message }}</p>
    @isset($action)
        <form method="POST" action="{{ $action }}">
            <input type="hidden" name="email" value="{{ $email }}">
            <input type="hidden" name="token" value="{{ $token }}">
            <button type="submit">Unsubscribe</button>
        </form>
    @endisset
</main>
</body>
</html>
