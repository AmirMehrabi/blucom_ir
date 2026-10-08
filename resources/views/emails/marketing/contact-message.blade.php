<h1>پیام جدید از سایت بلوکام</h1>
<p><strong>نام:</strong> {{ $submission['name'] }}</p>
<p><strong>موضوع:</strong> {{ $submission['topic'] ?: 'تعیین نشده' }}</p>
<p><strong>تلفن:</strong> {{ $submission['phone'] ?: 'ثبت نشده' }}</p>
<p><strong>ایمیل:</strong> {{ $submission['email'] ?: 'ثبت نشده' }}</p>
<hr>
<p style="white-space:pre-line">{{ $submission['message'] }}</p>
