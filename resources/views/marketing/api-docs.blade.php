@extends('layouts.marketing')
@section('title', 'مستندات API تماس صوتی | بلوکام')
@section('description', 'راهنمای احراز هویت، ارسال تماس صوتی، پیگیری نتیجه و رفع خطا با نمونه‌های cURL و PHP.')

@section('content')
<style>
    .api-docs{padding-block:clamp(24px,5vw,72px);line-height:1.9;color:#172b48}
    .api-docs h1{font-size:clamp(28px,5vw,48px);line-height:1.3;margin:0 0 12px}
    .api-docs h2{font-size:23px;margin:0 0 12px}
    .api-docs h3{font-size:16px;margin:22px 0 6px}
    .api-docs p{margin:8px 0 16px}
    .api-docs a{color:#1746ae;text-decoration:underline;text-underline-offset:4px}
    .api-docs code,.api-docs pre{font-family:ui-monospace,monospace}
    .api-docs code{overflow-wrap:anywhere}
    .api-docs pre{direction:ltr;text-align:left;background:#101e35;color:#e8f2ff;border-radius:12px;padding:16px;overflow:auto;font-size:13px;line-height:1.6;tab-size:4}
    .api-docs pre code{overflow-wrap:normal}
    .api-docs-grid{display:grid;grid-template-columns:210px minmax(0,1fr);gap:24px;margin-top:24px}
    .api-docs nav,.api-docs section{border:1px solid #dce5ef;border-radius:16px;background:white;padding:clamp(14px,3vw,26px)}
    .api-docs nav{position:sticky;top:18px;align-self:start;display:grid;gap:10px}
    .api-docs section{scroll-margin-top:24px;margin-bottom:16px}
    .api-docs dt{font-weight:800}
    .api-docs dd{margin:0 0 10px}
    @media(max-width:760px){.api-docs-grid{grid-template-columns:1fr}.api-docs nav{position:static;display:flex;flex-wrap:wrap}.api-docs pre{font-size:12px}}
</style>
<main id="main" class="landing-container api-docs">
    <header>
        <h1>مستندات API تماس صوتی</h1>
        <p>با یک شماره مقصد و یک فایل صوتی تماس بگیرید. بلوکام پس از پاسخ مقصد، پیام را پخش می‌کند.</p>
        <a href="{{ route('customer.audio-calls.index') }}">تماس صوتی و کلیدهای API در پنل</a>
    </header>
    <div class="api-docs-grid">
        <nav aria-label="فهرست مستندات">
            <strong>در این صفحه</strong>
            <a href="#authentication">۱. احراز هویت</a>
            <a href="#create-call">۲. ایجاد تماس</a>
            <a href="#status">۳. پیگیری تماس</a>
            <a href="#upload">۴. بارگذاری فایل</a>
            <a href="#errors">۵. خطاها و تلاش دوباره</a>
        </nav>
        <div>
            <section id="authentication">
                <h2>احراز هویت</h2>
                <p>مالک حساب از بخش تماس صوتی، یک کلید API می‌سازد. کلید را در محیط سرور ذخیره کنید و آن را در سربرگ <code dir="ltr">Authorization: Bearer</code> بفرستید. کلید فقط هنگام ساخت نمایش داده می‌شود.</p>
                <p>آدرس پایه <code dir="ltr">https://{{ config('portal.customer_domain') }}/api/v1</code> است. همهٔ پاسخ‌ها JSON هستند. تماس صوتی باید برای خط خروجی شما فعال باشد.</p>
                <pre><code>export BLUCOM_API_KEY='bac_REPLACE_WITH_YOUR_KEY'
export BLUCOM_API_BASE='https://{{ config('portal.customer_domain') }}/api/v1'</code></pre>
                <p>هر دو نمونهٔ cURL و PHP در ادامه همین کلید را از محیط می‌خوانند.</p>
            </section>
            <section id="create-call">
                <h2>ایجاد تماس</h2>
                <p><code dir="ltr">POST /audio-calls</code> یک تماس را برای اجرا می‌پذیرد. برای هر تماس جدید یک <code dir="ltr">Idempotency-Key</code> تازه بسازید.</p>
                <dl>
                    <dt><code>to</code></dt><dd>شماره مقصد، ترجیحاً با پیش‌شمارهٔ کشور و علامت مثبت.</dd>
                    <dt><code>audio_url</code> یا <code>audio_id</code></dt><dd>یکی را بفرستید. لینک باید مستقیم، عمومی و HTTPS باشد. سرور فایل باید با پاسخ ۲۰۰ فایل WAV یا MP3 را برگرداند. سقف ۸ مگابایت و ۶۰ ثانیه است. شناسهٔ فایل از کتابخانه یا پاسخ بارگذاری به دست می‌آید.</dd>
                    <dt><code>caller_id_id</code></dt><dd>شناسهٔ خط در پنل. اگر فقط یک خط مجاز دارید، می‌توانید این فیلد را حذف کنید.</dd>
                </dl>
                <h3>cURL</h3>
                <pre><code>curl -i "$BLUCOM_API_BASE/audio-calls" \
  -H "Authorization: Bearer $BLUCOM_API_KEY" \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: order-1042-notification-1' \
  --data '{"to":"+989121234567","audio_url":"https://media.example.com/ready.mp3"}'</code></pre>
                <h3>PHP با افزونهٔ cURL</h3>
                <pre><code>&lt;?php
function blucom(string $path, array $options = [], ?string $key = null): array
{
    $curl = curl_init(getenv('BLUCOM_API_BASE') . $path);
    curl_setopt_array($curl, $options + [
        CURLOPT_HTTPHEADER =&gt; [
            'Authorization: Bearer ' . ($key ?? getenv('BLUCOM_API_KEY')),
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER =&gt; true,
        CURLOPT_TIMEOUT =&gt; 15,
    ]);
    $body = curl_exec($curl);
    if ($body === false) {
        $message = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException($message);
    }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return [$status, json_decode($body, true, flags: JSON_THROW_ON_ERROR)];
}

[$status, $call] = blucom('/audio-calls', [
    CURLOPT_POST =&gt; true,
    CURLOPT_HTTPHEADER =&gt; [
        'Authorization: Bearer ' . getenv('BLUCOM_API_KEY'),
        'Content-Type: application/json',
        'Accept: application/json',
        'Idempotency-Key: order-1042-notification-1',
    ],
    CURLOPT_POSTFIELDS =&gt; json_encode([
        'to' =&gt; '+989121234567',
        'audio_url' =&gt; 'https://media.example.com/ready.mp3',
    ], JSON_THROW_ON_ERROR),
]);
if ($status !== 202) {
    throw new RuntimeException($call['message'] ?? "HTTP $status");
}
echo $call['id'];</code></pre>
                <h3>پاسخ موفق، <code dir="ltr">202 Accepted</code></h3>
                <pre><code>{
  "id": "a10e4882-6c69-4d92-9f9b-309716e1a3fd",
  "to": "+989121234567",
  "caller_id_id": 42,
  "caller_id": "+982100000000",
  "audio_id": null,
  "status": "preparing_audio",
  "outcome": null,
  "created_at": "2026-11-11T08:00:00.000000Z",
  "answered_at": null,
  "playback_started_at": null,
  "finished_at": null
}</code></pre>
            </section>
            <section id="status">
                <h2>پیگیری تماس</h2>
                <p><code dir="ltr">GET /audio-calls/{id}</code> همان ساختار را با وضعیت فعلی برمی‌گرداند. هر چند ثانیه وضعیت را بخوانید و با رسیدن به <code>finished</code> متوقف شوید. وضعیت‌ها شامل <code>preparing_audio</code>، <code>queued</code>، <code>dialing</code> و <code>playing</code> هستند.</p>
                <h3>cURL</h3>
                <pre><code>CALL_ID='a10e4882-6c69-4d92-9f9b-309716e1a3fd'
curl -i "$BLUCOM_API_BASE/audio-calls/$CALL_ID" \
  -H "Authorization: Bearer $BLUCOM_API_KEY"</code></pre>
                <h3>PHP</h3>
                <pre><code>[$status, $result] = blucom('/audio-calls/' . rawurlencode($call['id']));
if ($status === 200 &amp;&amp; $result['status'] === 'finished') {
    echo $result['outcome'];
}</code></pre>
                <p><code>completed</code> یعنی سوئیچ فایل را کامل پخش کرده است. پاسخ‌گویی صندوق صوتی نیز می‌تواند پخش را آغاز کند. نتیجه‌هایی مانند <code>busy</code>، <code>no_answer</code>، <code>interrupted</code> و <code>audio_unavailable</code> در یک پاسخ HTTP 200 گزارش می‌شوند.</p>
            </section>
            <section id="upload">
                <h2>بارگذاری فایل برای استفادهٔ مجدد</h2>
                <p><code dir="ltr">POST /audio-assets</code> برای فایل‌های تا ۲ مگابایت، دو فیلد فرم <code>name</code> و <code>audio</code> می‌گیرد. پاسخ <code dir="ltr">201 Created</code> شامل <code>id</code>، <code>name</code> و <code>duration_seconds</code> است. در درخواست تماس، <code>id</code> را در <code>audio_id</code> بفرستید.</p>
                <h3>cURL</h3>
                <pre><code>curl -i "$BLUCOM_API_BASE/audio-assets" \
  -H "Authorization: Bearer $BLUCOM_API_KEY" \
  -F 'name=Order ready' -F 'audio=@ready.mp3'</code></pre>
                <h3>PHP</h3>
                <pre><code>[$status, $asset] = blucom('/audio-assets', [
    CURLOPT_POST =&gt; true,
    CURLOPT_POSTFIELDS =&gt; [
        'name' =&gt; 'Order ready',
        'audio' =&gt; new CURLFile(__DIR__ . '/ready.mp3'),
    ],
]);
if ($status === 201) {
    echo $asset['id'];
}</code></pre>
            </section>
            <section id="errors">
                <h2>خطاها و تلاش دوباره</h2>
                <dl>
                    <dt><code>401</code> · کلید نامعتبر</dt><dd>کلید و سربرگ Authorization را بررسی کنید.</dd>
                    <dt><code>403</code> · قابلیت غیرفعال</dt><dd>فعال‌سازی تماس صوتی را در پنل بررسی کنید.</dd>
                    <dt><code>404</code> · تماس پیدا نشد</dt><dd>شناسه و حسابی را که تماس را ساخته بررسی کنید.</dd>
                    <dt><code>409</code> · تداخل کلید درخواست</dt><dd>این کلید قبلاً برای ورودی دیگری استفاده شده است. برای تماس جدید کلید تازه بسازید.</dd>
                    <dt><code>422</code> · داده نامعتبر</dt><dd>برای فیلدهای نامعتبر به شیء <code>errors</code> مراجعه کنید.</dd>
                    <dt><code>429</code> / <code>503</code> · محدودیت یا وقفه</dt><dd>کمی صبر کنید و همان درخواست را با همان کلید تکرار کنید.</dd>
                </dl>
                <h3>نمونهٔ درخواست ناموفق با cURL</h3>
                <pre><code>curl -i "$BLUCOM_API_BASE/audio-calls/a10e4882-6c69-4d92-9f9b-309716e1a3fd" \
  -H 'Authorization: Bearer invalid-key'</code></pre>
                <h3>نمونهٔ درخواست ناموفق با PHP</h3>
                <pre><code>[$status, $error] = blucom('/audio-calls/a10e4882-6c69-4d92-9f9b-309716e1a3fd', key: 'invalid-key');
if ($status === 401) {
    echo $error['message'];
}</code></pre>
                <pre><code>HTTP/1.1 401 Unauthorized
Content-Type: application/json

{"message":"Invalid API key."}</code></pre>
                <h3>نمونهٔ خطای اعتبارسنجی، <code dir="ltr">422 Unprocessable Content</code></h3>
                <pre><code>{
  "message": "The to field is required.",
  "errors": {"to": ["The to field is required."]}
}</code></pre>
                <p>اگر پاسخ ایجاد تماس به شما نرسید، همان ورودی و همان <code dir="ltr">Idempotency-Key</code> را دوباره بفرستید. بلوکام شناسهٔ تماس موجود را برمی‌گرداند.</p>
            </section>
        </div>
    </div>
</main>
@endsection
