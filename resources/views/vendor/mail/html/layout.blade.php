<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
@media only screen and (max-width: 620px) {
.mail-shell {
padding: 24px 12px !important;
}

.inner-body,
.footer {
width: 100% !important;
}

.brand-cell {
display: block !important;
width: 100% !important;
}

.brand-badge-cell {
display: block !important;
padding-top: 18px !important;
text-align: left !important;
width: 100% !important;
}

.content-cell,
.brand-header {
padding-left: 24px !important;
padding-right: 24px !important;
}
}

@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
}
}
</style>
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center" class="mail-shell">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="680" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="brand-header">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="brand-cell">
<p class="eyebrow">Argusly account</p>
<h1 class="brand-title">{{ config('app.name') }}</h1>
</td>
<td class="brand-badge-cell" align="right">
<span class="brand-badge">Argusly</span>
</td>
</tr>
</table>
</td>
</tr>
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
