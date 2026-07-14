<?php

namespace App\Models;

use App\Enums\FriendshipStatus;
use Binafy\LaravelUserMonitoring\Traits\Actionable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A directed friend relationship (requester -> addressee) with its status. */
class Friendship extends Model
{
    // Action monitoring: log create/update/delete pertemanan (binafy/laravel-user-monitoring).
    use Actionable;

    protected $fillable = [
        'requester_id',
        'addressee_id',
        'status',
    ];

    protected $casts = [
        'status' => FriendshipStatus::class,
    ];

    /** The user who sent the request. */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** The user who received the request. */
    public function addressee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'addressee_id');
    }

    /**
     * Relasi $meId terhadap BANYAK user sekaligus dalam SATU query, dipetakan
     * user_id => ['relation', 'friendship_id'].
     *
     * Pengganti pemanggilan User::friendshipWith() di dalam loop (satu query per
     * baris = N+1). Dipakai daftar pencarian teman & baris leaderboard, yang
     * sama-sama perlu tahu status relasi untuk memilih tombol yang tepat.
     *
     * relation: 'friends' | 'sent' | 'incoming'. User tanpa baris friendship
     * TIDAK muncul di peta -- pemanggil memperlakukan absennya kunci sebagai 'none'.
     *
     * @param  array<int, int>  $otherIds
     * @return array<int, array{relation: string, friendship_id: int}>
     */
    public static function relationMapFor(int $meId, array $otherIds): array
    {
        $otherIds = array_values(array_unique(array_map('intval', $otherIds)));

        if (empty($otherIds)) {
            return [];
        }

        return static::query()
            ->where(fn ($q) => $q->where('requester_id', $meId)->whereIn('addressee_id', $otherIds))
            ->orWhere(fn ($q) => $q->where('addressee_id', $meId)->whereIn('requester_id', $otherIds))
            ->get()
            ->mapWithKeys(function (self $f) use ($meId) {
                $otherId = $f->requester_id === $meId ? $f->addressee_id : $f->requester_id;

                $relation = match (true) {
                    $f->status === FriendshipStatus::Accepted => 'friends',
                    // Pending: arah panah menentukan tombol yang ditampilkan.
                    $f->requester_id === $meId => 'sent',
                    default => 'incoming',
                };

                return [$otherId => ['relation' => $relation, 'friendship_id' => $f->id]];
            })
            ->all();
    }
}
