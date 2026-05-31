<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        NotificationService $notifications,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user) ?? abort(403);
        $brand = $currentBrand->get($user);

        return view('app.notifications.index', [
            'account' => $account,
            'brand' => $brand,
            'events' => $notifications->eventsForUser($user, $account, $brand, 50),
            'unreadCount' => $notifications->unreadCount($user, $account, $brand),
            'types' => NotificationPreference::TYPES,
            'channels' => NotificationPreference::CHANNELS,
            'preferences' => $notifications->preferenceMatrix($user, $account, $brand),
        ]);
    }

    public function preferences(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        NotificationService $notifications,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user) ?? abort(403);
        $brand = $currentBrand->get($user);

        $notifications->updatePreferences($user, $account, $brand, $request->array('preferences'));

        return back()->with('status', 'Notification preferences updated.');
    }

    public function markRead(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        NotificationEvent $notification,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user) ?? abort(403);
        $brand = $currentBrand->get($user);

        abort_unless($notification->user_id === $user->id, 404);
        abort_unless($notification->account_id === $account->id, 404);
        abort_unless($brand === null || $notification->brand_id === null || $notification->brand_id === $brand->id, 404);

        $notification->markRead();

        return back()->with('status', 'Notification marked as read.');
    }
}
