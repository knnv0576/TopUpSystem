@extends('admin.layout')
@section('title', '稽核紀錄')
@section('content')
<h1>稽核紀錄</h1>
<div class="card">
    <form method="get" action="{{ route('admin.audit') }}" class="actions">
        <div>
            <label for="action">動作開頭（例如 refund.）</label>
            <input id="action" type="text" name="action" value="{{ $action }}" maxlength="48">
        </div>
        <button class="secondary" type="submit">篩選</button>
    </form>
</div>
<div class="card">
    @include('admin.partials.audit-table', ['logs' => $logs])
    <div class="pagination">{{ $logs->links('pagination::simple-tailwind') }}</div>
</div>
@endsection
