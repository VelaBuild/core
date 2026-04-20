<?php

namespace VelaBuild\Core\Http\Controllers\Admin\Marketplace;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\InstalledPackage;
use VelaBuild\Core\Services\Marketplace\MarketplaceClient;
use VelaBuild\Core\Services\Marketplace\MarketplaceSettingsService;

class MarketplaceBrowseController extends Controller
{
    public function __construct(
        private MarketplaceClient $client,
        private MarketplaceSettingsService $settings,
    ) {}

    public function index(Request $request)
    {
        abort_if(Gate::denies('marketplace_browse'), Response::HTTP_FORBIDDEN);

        $filters = $request->only(['search', 'category', 'type', 'page']);
        $plugins = $this->client->getCatalog($filters);

        return view('vela::admin.marketplace.index', compact('plugins', 'filters'));
    }

    public function search(Request $request)
    {
        abort_if(Gate::denies('marketplace_browse'), Response::HTTP_FORBIDDEN);

        $plugins = $this->client->getCatalog(['search' => $request->input('search')]);

        return response()->json($plugins);
    }

    public function show(string $slug)
    {
        abort_if(Gate::denies('marketplace_browse'), Response::HTTP_FORBIDDEN);

        $plugin = $this->client->getPlugin($slug);

        if (is_null($plugin)) {
            abort(404);
        }

        $installed = InstalledPackage::where('composer_name', $plugin['composer_name'] ?? '')->first();

        return view('vela::admin.marketplace.show', compact('plugin', 'installed'));
    }
}
