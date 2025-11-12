<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Redis;
use Illuminate\Database\Eloquent\Concerns\HasUuids;


/**
 * BaseModel
 * - UUID primary key
 * - Simple Redis helpers
 * - New models should extend this
 */
abstract class BaseModel extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }

    /**
     * Redis cache key for this model instance.
     */
    public function cacheKey(): string
    {
        return sprintf('%s:%s', static::class, $this->getKey());
    }

    public function cache(int $ttl = 3600): static
    {
        Redis::setex($this->cacheKey(), $ttl, $this->toJson());
        return $this;
    }

    public static function fromCache(string $id): ?static
    {
        $cached = Redis::get(sprintf('%s:%s', static::class, $id));
        if ($cached) {
            $attributes = json_decode($cached, true);
            $instance = (new static())->newFromBuilder($attributes);
            $instance->exists = true;
            return $instance;
        }
        return static::find($id);
    }

    public function forgetCache(): void
    {
        Redis::del($this->cacheKey());
    }

    public function refreshCache(): static
    {
        $fresh = $this->fresh();
        if ($fresh) {
            $fresh->cache();
            return $fresh;
        }
        return $this;
    }
}
