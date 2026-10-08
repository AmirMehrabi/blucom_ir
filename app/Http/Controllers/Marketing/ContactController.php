<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Mail\MarketingContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ContactController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:254', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:32', 'required_without:email'],
            'topic' => ['nullable', 'in:راه‌اندازی تلفن کاری,خرید شماره,پشتیبانی مشتریان,سایر'],
            'message' => ['required', 'string', 'min:10', 'max:4000'],
            'website' => ['nullable', 'string', 'max:200'],
        ], [
            'name.required' => 'لطفاً نام خود را وارد کنید.',
            'name.max' => 'نام شما نباید بیشتر از ۱۲۰ نویسه باشد.',
            'email.email' => 'ایمیل را با قالب درست وارد کنید.',
            'email.max' => 'ایمیل بیش از حد طولانی است.',
            'email.required_without' => 'لطفاً ایمیل یا شماره تماس خود را وارد کنید.',
            'phone.required_without' => 'لطفاً ایمیل یا شماره تماس خود را وارد کنید.',
            'phone.max' => 'شماره تماس بیش از حد طولانی است.',
            'topic.in' => 'لطفاً یکی از موضوع‌های فهرست را انتخاب کنید.',
            'message.required' => 'لطفاً پیام خود را بنویسید.',
            'message.min' => 'پیام را کمی کامل‌تر بنویسید تا بتوانیم دقیق‌تر راهنمایی کنیم.',
            'message.max' => 'پیام نباید بیشتر از ۴۰۰۰ نویسه باشد.',
        ]);

        // Quietly accept the honeypot so automated form fillers cannot use it to
        // distinguish a rejected submission from a successful one.
        if ($request->filled('website')) {
            return redirect()->to(route('contact').'#contact-form')->with('contact-sent', true);
        }
        unset($data['website']);

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            return redirect()->to(route('contact').'#contact-form')
                ->withInput($request->only('name', 'email', 'phone', 'topic', 'message'))
                ->with('contact-error', true);
        }

        try {
            Mail::to(config('marketing.contact_email'))
                ->send(new MarketingContactMessage($data));
        } catch (Throwable) {
            return redirect()->to(route('contact').'#contact-form')
                ->withInput($request->only('name', 'email', 'phone', 'topic', 'message'))
                ->with('contact-error', true);
        }

        return redirect()->to(route('contact').'#contact-form')->with('contact-sent', true);
    }
}
