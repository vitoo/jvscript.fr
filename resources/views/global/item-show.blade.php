{{--
    Fiche d'un script ou d'un skin.
    Paramètres : $item ('script' | 'skin'), $script (le modèle Script ou Skin)
--}}
<?php
$authorName = null != $script->user_id ? $script->user()->first()->name : $script->autor;
?>

@if (session('message'))
<div class="alert alert-success">
    {{ session('message') }}
</div>
@endif

<div class="item-header">
    <div>
        <h1>{{$script->name}}
            @if(null != $script->version)
            <span class="item-version">v{{$script->version}}</span>
            @endif
        </h1>
        @if($authorName)
        <p class="item-byline">
            par <a href="{{url('/search/'.$authorName)}}" data-toggle="tooltip" data-placement="right" title="Voir tous les {{$item}}s de {{$authorName}}">{{$authorName}}</a>
        </p>
        @endif
    </div>

    @if($item == 'skin')
    <a target="_blank" class="btn btn-primary item-install" href="{{route('skin.install',$script->slug)}}" onclick="document.getElementById('install').submit(); return false;"><i class="fa fa-download"></i> Installer</a>
    <form id="install" action="{{route('skin.install',$script->slug)}}" target="_blank" method="POST" style="display: none;">
        {{ csrf_field() }}
    </form>
    @else
    <a target="_blank" class="btn btn-primary item-install" href="{{route('script.install',$script->slug)}}"><i class="fa fa-download"></i> Installer</a>
    @endif
</div>

{{-- formulaires de vote, déclenchés par les étoiles --}}
@for ($i = 1; $i <= 5 ; $i++)
<form id="note-{{$i}}" action="{{route($item.'.note',['slug' => $script->slug , 'note' => $i  ])}}" method="POST" style="display: none;">
    {{ csrf_field() }}
    <input type="submit" name="note-{{$i}}" style="display: none;" />
</form>
@endfor

<div class="row">
    <div class="col-md-8">

        @if ( $script->photo_url != null )
        <div class="item-card item-image">
            <a href="#" data-toggle="modal" data-target="#myModal">
                <img src="{{$script->photo_url}}" alt="{{$script->name}} logo" />
            </a>
        </div>
        <div class="modal fade" id="myModal" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-body text-center">
                        <img class="img-responsive" style="margin: 0 auto;" src="{{$script->photo_url}}" alt="{{$script->name}} logo" />
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- sur mobile/tablette, les infos passent juste sous l'image --}}
        <div class="visible-xs visible-sm">
            @include('global.item-infos')
        </div>

        @if( $script->description != '' )
        <div class="item-card item-desc">
            {!! $script->description !!}
        </div>
        @endif

        @if (Auth::check() && (Auth::user()->isAdmin() || Auth::user()->id == $script->user_id || Auth::user()->id == $script->poster_user_id))
        <?php $isAdmin = Auth::user()->isAdmin(); $subject = $isAdmin ? 'Ce '.$item : 'Votre '.$item; ?>
        <div class="item-card admin">
            @if($script->status == 0)
            <div class="alert alert-warning" role="alert">{{$subject}} est en attente de validation</div>
            @elseif($script->status == 1)
            <div class="alert alert-success" role="alert">{{$subject}} a été validé.</div>
            @elseif($script->status == 2)
            <div class="alert alert-danger" role="alert">{{$subject}} a été refusé.</div>
            @endif
            <p style="margin: 0;">
                <a href="{{route($item.'.edit',$script->slug)}}" class="btn btn-sm btn-primary">Editer</a>
                <a href="{{route($item.'.delete',$script->slug)}}" class="btn btn-sm btn-danger" data-toggle="confirmation">Supprimer</a>
                @if($isAdmin)
                &nbsp; Validation :
                <a href="{{route($item.'.validate',$script->slug)}}" class="btn btn-sm btn-success" data-toggle="confirmation">Valider</a>
                <a href="{{route($item.'.refuse',$script->slug)}}" class="btn btn-sm btn-warning" data-toggle="confirmation">Refuser</a>
                @endif
            </p>
        </div>
        @endif

        <div class="item-card">
            @include('global.comments', ['commentClass' => '', 'recaptcha' => 1])
        </div>
    </div>

    <div class="col-md-4 hidden-xs hidden-sm">
        @include('global.item-infos')
    </div>
</div>
