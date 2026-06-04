<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return 'v4-'.parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'flash' => [
                'success' => fn () => $request->session()->get('flash.success'),
                'error' => fn () => $request->session()->get('flash.error'),
            ],
            'auth' => [
                'user' => fn () => $request->user()
                    ? ['id' => $request->user()->id, 'name' => $request->user()->name, 'email' => $request->user()->email]
                    : null,
                'unread' => fn () => $request->user()?->unreadNotifications()->count() ?? 0,
                'notifications' => fn () => $request->user()
                    ? $request->user()->notifications()->latest()->limit(10)->get()
                        ->map(fn ($n) => [
                            'id' => $n->id,
                            'message' => $n->data['message'] ?? '通知',
                            'run_id' => $n->data['run_id'] ?? null,
                            'read' => $n->read_at !== null,
                            'at' => $n->created_at?->diffForHumans(),
                        ])->all()
                    : [],
            ],
        ];
    }
}
