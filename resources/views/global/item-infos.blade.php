{{-- Note, installs, dates et liens d'un script/skin (affiché dans la colonne de droite, ou sous l'image sur mobile) --}}
<div class="item-card item-infos">
    <div class="info-row">
        <span class="info-key">Note</span>
        <span class="info-stars" title="Cliquez sur une étoile pour voter">
            <?php $note = round($script->note * 2) / 2; ?>
            @for ($i = 1; $i <= 5 ; $i++)
            <?php $icon = $i <= $note ? 'fa-star' : ($note > $i - 1 ? 'fa-star-half-o' : 'fa-star-o'); ?>
            <a href="#" onclick="document.getElementById('note-{{$i}}').submit(); return false;"><i class="fa {{$icon}}" aria-hidden="true"></i></a>
            @endfor
            <span class="info-muted">({{$script->note_count}} votes)</span>
        </span>
    </div>
    <div class="info-row">
        <span class="info-key">Installations</span>
        <span><i class="fa fa-download info-muted"></i> {{$script->install_count}} install{{ $script->install_count > 1 ? 's' : '' }}</span>
    </div>
    <div class="info-row">
        <span class="info-key">Créé le</span>
        <span>{{$script->created_at->format('d/m/Y')}}</span>
    </div>
    @if(null != $script->last_update)
    <div class="info-row">
        <span class="info-key">Mis à jour le</span>
        <span>{{$script->last_update->format('d/m/Y')}}</span>
    </div>
    @endif
</div>

@if ( $script->repo_url != null || $script->topic_url != null || $script->website_url != null || $script->don_url != null )
<div class="item-card item-links">
    <h4>Liens</h4>
    @if ( $script->topic_url != null )
    <a target="_blank" href="{{$script->topic_url}}"><i class="fa fa-gamepad"></i> Topic JVC</a>
    @endif
    @if ( $script->website_url != null )
    <a target="_blank" href="{{$script->website_url}}"><i class="fa fa-globe"></i> Site web</a>
    @endif
    @if ( $script->repo_url != null )
    <a target="_blank" href="{{$script->repo_url}}"><i class="fa fa-code"></i> Contribuer</a>
    @endif
    @if ( $script->don_url != null )
    <a target="_blank" href="{{$script->don_url}}"><i class="fa fa-heart"></i> Don</a>
    @endif
</div>
@endif
