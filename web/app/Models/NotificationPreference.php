<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's choice about one kind of notification. Absent means the defaults.
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property bool $mail
 * @property bool $database
 * @property string|null $digest_frequency
 */
#[Fillable(['user_id', 'type', 'mail', 'database', 'digest_frequency'])]
class NotificationPreference extends Model
{
    protected function casts(): array
    {
        return [
            'mail' => 'boolean',
            'database' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
