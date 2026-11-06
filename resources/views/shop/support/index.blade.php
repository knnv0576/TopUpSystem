@extends('shop.layout')
@section('title', '客服支援')
@section('content')
<h1>客服支援</h1>
<div class="card">
    <h2>提出問題</h2>
    <p class="muted">客服會在後台回覆，回覆內容會顯示在這個頁面。請勿在訊息中填寫卡號、密碼或真實的銀行帳號。</p>
    <form method="post" action="{{ route('shop.support.store') }}">
        @csrf
        <label for="category">問題類別</label>
        <select id="category" name="category" required>
            <option value="">請選擇</option>
            @foreach ($categories as $value => $label)
                <option value="{{ $value }}" @selected(old('category', $prefillCategory) === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @error('category')<p class="field-error">{{ $message }}</p>@enderror

        <label for="order_no">相關訂單（選填）</label>
        <select id="order_no" name="order_no">
            <option value="">不指定</option>
            @foreach ($orders as $order)
                <option value="{{ $order->order_no }}" @selected(old('order_no', $prefillOrder) === $order->order_no)>{{ $order->order_no }}｜{{ $order->package_name }}｜{{ $order->playerStatusLabel() }}</option>
            @endforeach
        </select>
        @error('order_no')<p class="field-error">{{ $message }}</p>@enderror

        <label for="subject">主旨</label>
        <input type="text" id="subject" name="subject" value="{{ old('subject') }}" minlength="4" maxlength="100" required>
        @error('subject')<p class="field-error">{{ $message }}</p>@enderror

        <label for="body">問題描述</label>
        <textarea id="body" name="body" rows="6" minlength="10" maxlength="2000" required placeholder="請說明發生了什麼事、發生的時間，以及你看到的訊息">{{ old('body') }}</textarea>
        @error('body')<p class="field-error">{{ $message }}</p>@enderror

        <label for="contact_email">回覆通知 Email（選填）</label>
        <input type="text" id="contact_email" name="contact_email" value="{{ old('contact_email') }}" maxlength="120" inputmode="email" autocomplete="off" placeholder="name@example.test">
        <p class="muted">客服回覆時會寄通知信。這是本機 DEMO，信件只會出現在本機信箱 Mailpit，不會真的寄出。</p>
        @error('contact_email')<p class="field-error">{{ $message }}</p>@enderror

        <div class="actions"><button type="submit">送出問題</button></div>
    </form>
</div>

<div class="card">
    <h2>我的問題</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>工單編號</th><th>類別</th><th>主旨</th><th>狀態</th><th>最後更新</th></tr></thead>
        <tbody>
        @forelse ($tickets as $ticket)
            <tr>
                <td><a href="{{ route('shop.support.show', $ticket) }}"><span class="code">{{ $ticket->ticket_no }}</span></a></td>
                <td>{{ $ticket->categoryLabel() }}</td>
                <td>{{ $ticket->subject }}</td>
                <td><span class="badge {{ $ticket->status === 'answered' ? 'ok' : '' }}">{{ $ticket->status === 'open' ? '等待客服回覆' : $ticket->statusLabel() }}</span></td>
                <td>{{ $ticket->last_message_at->format('Y-m-d H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">還沒有提出過問題</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
