@if (config('voip.sip_tls.host'))
    <details class="mt-4 rounded-xl border border-slate-200 bg-white p-4 text-sm">
        <summary class="cursor-pointer font-bold">اتصال تلفن با SIP / TLS</summary>
        <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-2">
            <dt>سرور</dt><dd><bdi dir="ltr">{{ config('voip.sip_tls.host') }}</bdi></dd>
            <dt>پورت</dt><dd><bdi dir="ltr">{{ config('voip.sip_tls.port') }}</bdi></dd>
            <dt>نوع اتصال</dt><dd><bdi dir="ltr">TLS</bdi></dd>
        </dl>
        <p class="mt-3 leading-7 text-slate-700">از نام کاربری و رمز همان داخلی استفاده کنید. اگر Zoiper نام کاربری کامل می‌خواهد، قالب <bdi dir="ltr">داخلی@{{ config('voip.sip_tls.host') }}:{{ config('voip.sip_tls.port') }}</bdi> را وارد کنید. بررسی گواهی سرور را فعال نگه دارید و Outbound Proxy را خالی بگذارید.</p>
    </details>
@endif
