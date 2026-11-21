@if($latestScripts->isNotEmpty())
<div id="nouveautes" class="col-xs-12" style="margin-bottom: 20px;">
    <ul class="nav nav-tabs" role="tablist">
        <li role="presentation" class="active">
            <a href="#nouveaux-scripts" aria-controls="nouveaux-scripts" role="tab" data-toggle="tab">
                <i class="fa fa-star" aria-hidden="true"></i> Nouveaux scripts
            </a>
        </li>
        @if($updatedScripts->isNotEmpty())
        <li role="presentation">
            <a href="#scripts-mis-a-jour" aria-controls="scripts-mis-a-jour" role="tab" data-toggle="tab">
                <i class="fa fa-refresh" aria-hidden="true"></i> Mis à jour récemment
            </a>
        </li>
        @endif
    </ul>

    <div class="tab-content" style="padding-top: 20px;">
        <div role="tabpanel" class="tab-pane active" id="nouveaux-scripts">
            <div class="row">
                @foreach($latestScripts as $script)
                @include('home.script', ['lazy' => false])
                @endforeach
            </div>
        </div>
        @if($updatedScripts->isNotEmpty())
        <div role="tabpanel" class="tab-pane" id="scripts-mis-a-jour">
            <div class="row">
                @foreach($updatedScripts as $script)
                @include('home.script', ['lazy' => false])
                @endforeach
            </div>
        </div>
        @endif
    </div>
</div>
@endif
