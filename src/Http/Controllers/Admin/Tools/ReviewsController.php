<?php

namespace VelaBuild\Core\Http\Controllers\Admin\Tools;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Jobs\SyncGoogleReviewsJob;
use VelaBuild\Core\Models\Review;
use VelaBuild\Core\Services\Tools\GoogleReviewsService;
use VelaBuild\Core\Services\ToolSettingsService;

class ReviewsController extends Controller
{
    public function __construct(
        private ToolSettingsService $settings,
        private GoogleReviewsService $reviewsService,
    ) {}

    public function index()
    {
        abort_if(Gate::none(['tools_access', 'admin_tools_access']), Response::HTTP_FORBIDDEN);

        $reviews = Review::orderBy('review_date', 'desc')->paginate(20);
        $canConfigure = Gate::allows('admin_tools_access');

        return view('vela::admin.tools.reviews', [
            'reviews' => $reviews,
            'canConfigure' => $canConfigure,
            'isGoogleConfigured' => $this->reviewsService->isConfigured(),
            'placeId' => $this->settings->get('google_place_id'),
            'avgRating' => Review::published()->avg('rating'),
            'totalReviews' => Review::published()->count(),
        ]);
    }

    public function store(Request $request)
    {
        abort_if(Gate::none(['tools_access', 'admin_tools_access']), Response::HTTP_FORBIDDEN);

        $request->validate([
            'author' => 'required|string|max:255',
            'rating' => 'required|integer|min:1|max:5',
            'text' => 'nullable|string',
            'review_date' => 'nullable|date',
        ]);

        Review::create([
            'source' => 'manual',
            'author' => $request->input('author'),
            'rating' => (int) $request->input('rating'),
            'text' => $request->input('text'),
            'review_date' => $request->input('review_date', now()),
            'published' => true,
        ]);

        return redirect()->back()->with('message', __('vela::tools.reviews.review_added'));
    }

    public function update(Request $request, int $id)
    {
        abort_if(Gate::none(['tools_access', 'admin_tools_access']), Response::HTTP_FORBIDDEN);

        $review = Review::findOrFail($id);

        $request->validate([
            'author' => 'nullable|string|max:255',
            'rating' => 'nullable|integer|min:1|max:5',
            'text' => 'nullable|string',
            'published' => 'nullable|boolean',
        ]);

        $review->update($request->only(['author', 'rating', 'text', 'published']));

        return redirect()->back()->with('message', __('vela::tools.reviews.review_updated'));
    }

    public function destroy(int $id)
    {
        abort_if(Gate::none(['tools_access', 'admin_tools_access']), Response::HTTP_FORBIDDEN);

        Review::findOrFail($id)->delete();

        return redirect()->back()->with('message', __('vela::tools.reviews.review_deleted'));
    }

    public function sync()
    {
        abort_if(Gate::none(['tools_access', 'admin_tools_access']), Response::HTTP_FORBIDDEN);

        if (!$this->reviewsService->isConfigured()) {
            return response()->json(['success' => false, 'message' => __('vela::tools.reviews.google_not_configured')], 400);
        }

        SyncGoogleReviewsJob::dispatch();

        return response()->json(['success' => true, 'message' => __('vela::tools.reviews.sync_queued')]);
    }

    public function updateConfig(Request $request)
    {
        abort_if(Gate::denies('admin_tools_access'), Response::HTTP_FORBIDDEN);

        $request->validate([
            'google_places_api_key' => 'nullable|string',
            'google_place_id' => 'nullable|string|max:255',
        ]);

        foreach (['google_places_api_key', 'google_place_id'] as $key) {
            if (!$this->settings->isEnvLocked($key) && $request->has($key)) {
                $val = $request->input($key);
                if ($val === '') {
                    $this->settings->set($key, null);
                } elseif ($val !== null && $val !== 'unchanged') {
                    $this->settings->set($key, $val);
                }
            }
        }

        return redirect()->back()->with('message', __('vela::tools.reviews.settings_saved'));
    }
}
