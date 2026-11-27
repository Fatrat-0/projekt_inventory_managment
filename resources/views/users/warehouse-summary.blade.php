@if($user->role === 'admin')
    Minden raktár
@elseif($user->memberships_count === 1)
    {{ $user->warehouseMemberships->first()->warehouse->warehouse_name }}
@elseif($user->memberships_count)
    {{ $user->memberships_count }} raktár
@else
    Tagság nélkül
@endif
