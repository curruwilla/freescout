{!! (new Html2Text\Html2Text($auto_reply_message))->getText() !!}

@include('emails/customer/autoral/signature_text')
@if (\App\Option::get('email_branding'))

-----------------------------------------------------------
{!! __('Support powered by :app_name — Free open source help desk & shared mailbox', ['app_name' => \Config::get('app.name')]) !!}
@endif