<?php

namespace Saviogodinho2002\Drifguard\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** Model de fixture genérico (domínio de blog) — prova que o pacote não depende de nenhum domínio específico. */
class Post extends Model
{
    protected $fillable = ['title', 'body', 'author_id', 'published_at'];

    public function author()
    {
        return $this->belongsTo(Author::class);
    }
}
