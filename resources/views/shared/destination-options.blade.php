<option value="">— انتخاب پاسخ‌گو —</option>
<optgroup label="یک نفر">
@foreach ($extensions as $extension)<option value="extension:{{ $extension->id }}" @selected(($selected ?? '') === 'extension:'.$extension->id)>{{ $extension->display_name ?: 'داخلی '.$extension->extension }} · {{ $extension->extension }}</option>@endforeach
</optgroup>
@if ($queues->isNotEmpty())<optgroup label="یک تیم">@foreach ($queues as $queue)<option value="queue:{{ $queue->id }}" @selected(($selected ?? '') === 'queue:'.$queue->id)>{{ $queue->name }}</option>@endforeach</optgroup>@endif
<optgroup label="منوی منتشرشده">@foreach ($menus as $menu)<option value="ivr:{{ $menu->id }}" @selected(($selected ?? '') === 'ivr:'.$menu->id)>{{ $menu->name }}</option>@endforeach</optgroup>
