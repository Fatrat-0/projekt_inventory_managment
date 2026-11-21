@php($stockRow = $stockRow ?? 'location')
@foreach($stockScopes as $scope)
    <p x-show="{{ $stockRow }}?.product_stock?.{{ $scope }}" data-putaway-stock="{{ $scope }}" class="min-w-0 text-sm text-gray-700">
        @switch($scope)
            @case('bin') <span>Rekeszkészlet:</span> @break
            @case('level') <span>Szint összesen:</span> @break
            @case('system')
                <span x-text="{{ $stockRow }}?.product_stock?.system?.kind === 'floor' ? 'Tárolóterület összesen:' : 'Állvány összesen:'"></span>
                @break
        @endswitch
        <strong x-text="stockCaption({{ $stockRow }}?.product_stock?.{{ $scope }})"></strong>
    </p>
@endforeach
