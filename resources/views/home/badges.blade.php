@if($item->isNew())
<span class="label label-success">Nouveau</span>
@elseif($item->isRecentlyUpdated())
<span class="label label-info" title="Mis à jour le {{ $item->last_update->format('d/m/Y') }}">MAJ</span>
@endif
