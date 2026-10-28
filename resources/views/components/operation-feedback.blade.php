@if(session('success'))<div role="status" class="rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-800">{{ session('success') }}</div>@endif
@if($errors->any())<div role="alert" class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800"><ul class="list-disc pl-4">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
