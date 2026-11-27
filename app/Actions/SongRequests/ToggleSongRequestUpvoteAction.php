<?php

namespace App\Actions\SongRequests;

use App\Actions\Concerns\BroadcastsSafely;
use App\Events\Live\SongRequestUpdated;
use App\Models\SongRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Upvotes a request, or takes the upvote back. One upvote per listener,
 * enforced by the pivot's primary key; the count is kept on the request
 * so the queue can be sorted cheaply.
 */
class ToggleSongRequestUpvoteAction
{
    use BroadcastsSafely;

    /**
     * @return bool Whether the listener now upvotes the request.
     *
     * @throws ValidationException for closed requests or your own request
     */
    public function handle(User $user, SongRequest $request): bool
    {
        if (! $request->status->isOpen()) {
            throw ValidationException::withMessages(['request' => 'This request is no longer in the queue.']);
        }

        if ($request->user_id === $user->id) {
            throw ValidationException::withMessages(['request' => 'You cannot upvote your own request.']);
        }

        $upvoted = DB::transaction(function () use ($user, $request): bool {
            $removed = DB::table('song_request_upvotes')
                ->where('user_id', $user->id)
                ->where('song_request_id', $request->id)
                ->delete();

            if ($removed > 0) {
                $request->newQuery()->whereKey($request->id)->where('upvotes_count', '>', 0)->decrement('upvotes_count');

                return false;
            }

            $inserted = DB::table('song_request_upvotes')->insertOrIgnore([
                'user_id' => $user->id,
                'song_request_id' => $request->id,
                'created_at' => now(),
            ]);

            // 0 means a simultaneous tap already added it: count it once.
            if ($inserted > 0) {
                $request->newQuery()->whereKey($request->id)->increment('upvotes_count');
            }

            return true;
        });

        $request->refresh()->load('user', 'station');

        $this->broadcastSafely(new SongRequestUpdated($request));

        return $upvoted;
    }
}
