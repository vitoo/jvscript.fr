<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

class Script extends Model
{
    protected $casts = [
        'pinned' => 'boolean',
        'last_update' => 'datetime',
    ];

    /**
     * Get the user (owner)
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the poster user
     */
    public function poster_user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all of the tags for the post.
     */
    public function tags()
    {
        return $this->morphToMany('App\Model\Tag', 'taggable');
    }

    public function isValidated()
    {
        return $this->status == 1;
    }

    /**
     * Nombre de scripts par onglet du bloc « Nouveautés » de la page d'accueil
     */
    public const HOME_NEWS_COUNT = 8;

    /**
     * Durée pendant laquelle un script ajouté porte le badge « Nouveau »
     */
    public const NEW_BADGE_DAYS = 30;

    /**
     * Durée pendant laquelle un script mis à jour porte le badge « MAJ »
     */
    public const UPDATED_BADGE_DAYS = 14;

    public function isNew(): bool
    {
        return $this->created_at !== null
            && $this->created_at->gte(now()->subDays(self::NEW_BADGE_DAYS));
    }

    public function isRecentlyUpdated(): bool
    {
        return $this->last_update !== null
            && $this->last_update->gte(now()->subDays(self::UPDATED_BADGE_DAYS));
    }

    public function getPhotoUrlAttribute($value)
    {
        if ($value) {
            return '/storage/images/'.$value;
        }

        return null;
    }

    public function photoShortLink()
    {
        return str_replace('/storage/images/', '', $this->photo_url);
    }

    public function photoSmall()
    {
        if ($this->photo_url) {
            return '/storage/images/small-'.$this->photoShortLink();
        }

        return null;
    }

    public function statusLabel()
    {
        $label = ['En attente', 'Validé', 'Refusé'];

        return $label[$this->status];
    }

    /**
     * Get all of the item's comments.
     */
    public function comments()
    {
        return $this->morphMany('App\Model\Comment', 'commentable');
    }

    public function getUrlAttribute()
    {
        return $this->js_url;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'description',
        'autor',
        'js_url',
        'repo_url',
        'photo_url',
        'version',
        'last_update',
        'user_email',
        'don_url',
        'topic_url',
        'website_url',
        'sensibility',
        'user_id',
        'pinned',
    ];
}
