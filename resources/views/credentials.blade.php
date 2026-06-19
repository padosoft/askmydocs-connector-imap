<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IMAP Connector — Enter Credentials</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #f5f5f5;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 2rem 1rem;
            min-height: 100vh;
            margin: 0;
        }
        .card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 12px rgba(0,0,0,.1);
            padding: 2rem;
            width: 100%;
            max-width: 480px;
        }
        h1 { font-size: 1.3rem; margin: 0 0 1.5rem; color: #1a1a1a; }
        label { display: block; font-size: .875rem; font-weight: 600; margin-bottom: .25rem; color: #333; }
        input, select {
            display: block;
            width: 100%;
            padding: .5rem .75rem;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 1rem;
            margin-bottom: 1rem;
            background: #fff;
        }
        input:focus, select:focus { outline: 2px solid #4f6ef7; border-color: #4f6ef7; }
        .row { display: flex; gap: .75rem; }
        .row .field { flex: 1; }
        button[type="submit"] {
            width: 100%;
            padding: .625rem 1rem;
            background: #4f6ef7;
            color: #fff;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: .5rem;
        }
        button[type="submit"]:hover { background: #3b58e0; }
        .hint { font-size: .8rem; color: #666; margin-top: -.75rem; margin-bottom: 1rem; }
        @media (prefers-color-scheme: dark) {
            body { background: #1a1a1a; }
            .card { background: #2a2a2a; box-shadow: 0 2px 12px rgba(0,0,0,.4); }
            h1 { color: #eee; }
            label { color: #ccc; }
            input, select { background: #333; border-color: #555; color: #eee; }
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Connect IMAP Mailbox</h1>

        <form method="POST" action="{{ $storeRoute }}">
            @csrf
            {{-- Connector state token — required by handleOAuthCallback --}}
            <input type="hidden" name="state" value="{{ $state }}">

            {{-- Server settings --}}
            <div class="row">
                <div class="field">
                    <label for="host">IMAP Host</label>
                    <input
                        type="text"
                        id="host"
                        name="host"
                        placeholder="imap.example.com"
                        value="{{ old('host', $installation->config_json['connection']['host'] ?? '') }}"
                        required
                        autocomplete="off"
                    >
                </div>
                <div class="field" style="max-width: 110px;">
                    <label for="port">Port</label>
                    <input
                        type="number"
                        id="port"
                        name="port"
                        placeholder="993"
                        value="{{ old('port', $installation->config_json['connection']['port'] ?? 993) }}"
                        min="1"
                        max="65535"
                        required
                    >
                </div>
            </div>

            <label for="encryption">Encryption</label>
            <select id="encryption" name="encryption" required>
                @foreach (['ssl' => 'SSL/TLS (recommended)', 'tls' => 'STARTTLS', 'starttls' => 'STARTTLS (explicit)', 'none' => 'None (plaintext)'] as $value => $label)
                    <option
                        value="{{ $value }}"
                        @selected(old('encryption', $installation->config_json['connection']['encryption'] ?? 'ssl') === $value)
                    >{{ $label }}</option>
                @endforeach
            </select>

            {{-- Account credentials --}}
            <label for="username">Email / Username</label>
            <input
                type="email"
                id="username"
                name="username"
                placeholder="you@example.com"
                value="{{ old('username', $installation->config_json['connection']['username'] ?? '') }}"
                required
                autocomplete="username"
            >

            <label for="password">Password / App Password</label>
            <input
                type="password"
                id="password"
                name="password"
                placeholder="Enter your IMAP password"
                required
                autocomplete="current-password"
            >
            <p class="hint">Use an App Password if your account has two-factor authentication enabled.</p>

            <button type="submit">Save &amp; Connect</button>
        </form>
    </div>
</body>
</html>
