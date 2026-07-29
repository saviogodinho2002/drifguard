<?php

namespace Saviogodinho2002\DriftGuard\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** Model de fixture genérico (domínio de blog) — prova que o pacote não depende de nenhum domínio específico. */
class Author extends Model
{
    protected $fillable = ['name', 'email', 'bio'];

    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}
