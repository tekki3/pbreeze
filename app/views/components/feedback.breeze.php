<div @if(!empty($id)) id="{{ $id }}" @endif
    class="feedback {{ $kind === 'error' ? 'feedback-error' : 'feedback-success' }}"
    role="{{ $kind === 'error' ? 'alert' : 'status' }}">
    {!! $slot !!}
</div>
