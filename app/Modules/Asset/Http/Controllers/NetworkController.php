<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\DeleteNetwork;
use App\Modules\Asset\Actions\DeleteSubnet;
use App\Modules\Asset\Actions\SaveNetwork;
use App\Modules\Asset\Actions\SaveSubnet;
use App\Modules\Asset\Models\Network;
use App\Modules\Asset\Models\Subnet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Networks and their subnets, kept from the free-IP page (ip-check.manage, staff only).
 */
class NetworkController extends Controller
{
    public function store(Request $request, SaveNetwork $saveNetwork): RedirectResponse
    {
        $this->allow($request);
        $network = $saveNetwork->handle(null, $this->networkData($request));

        return redirect()->route('asset.ip-check', ['customer' => $network->customer_id ?? 'own', 'network' => $network->id])
            ->with('success', __('asset.ipam.network_saved'));
    }

    public function update(Request $request, Network $network, SaveNetwork $saveNetwork): RedirectResponse
    {
        $this->allow($request);
        $saveNetwork->handle($network, $this->networkData($request));

        return back()->with('success', __('asset.ipam.network_saved'));
    }

    public function destroy(Request $request, Network $network, DeleteNetwork $deleteNetwork): RedirectResponse
    {
        $this->allow($request);
        $deleteNetwork->handle($network);

        return redirect()->route('asset.ip-check')->with('success', __('asset.ipam.network_deleted'));
    }

    public function storeSubnet(Request $request, SaveSubnet $saveSubnet): RedirectResponse
    {
        $this->allow($request);
        $subnet = $saveSubnet->handle(null, $this->subnetData($request));
        $customerId = $subnet->network?->customer_id;

        return redirect()->route('asset.ip-check', ['customer' => $customerId ?? 'own', 'network' => $subnet->network_id, 'subnet' => $subnet->id])
            ->with('success', __('asset.ipam.subnet_saved'));
    }

    public function updateSubnet(Request $request, Subnet $subnet, SaveSubnet $saveSubnet): RedirectResponse
    {
        $this->allow($request);
        $saveSubnet->handle($subnet, $this->subnetData($request));

        return back()->with('success', __('asset.ipam.subnet_saved'));
    }

    public function destroySubnet(Request $request, Subnet $subnet, DeleteSubnet $deleteSubnet): RedirectResponse
    {
        $this->allow($request);
        $deleteSubnet->handle($subnet);

        return redirect()->route('asset.ip-check', ['network' => $subnet->network_id])->with('success', __('asset.ipam.subnet_deleted'));
    }

    /**
     * @return array{customer_id: int|null, site_id: int|null, name: string, vlan_id: int|null, description: string|null}
     */
    private function networkData(Request $request): array
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            // The site must be one of the customer's.
            'site_id' => ['nullable', 'integer', Rule::exists('customer_sites', 'id')
                ->whereNull('deleted_at')
                ->where('customer_id', $request->integer('customer_id'))],
            'name' => ['required', 'string', 'max:255'],
            'vlan_id' => ['nullable', 'integer', 'between:1,4094'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], attributes: __('asset.ipam.fields'));

        return [
            'customer_id' => $data['customer_id'] ?? null,
            'site_id' => $data['site_id'] ?? null,
            'name' => $data['name'],
            'vlan_id' => $data['vlan_id'] ?? null,
            'description' => $data['description'] ?? null,
        ];
    }

    /**
     * @return array{network_id: int, cidr: string, gateway: string|null, description: string|null}
     */
    private function subnetData(Request $request): array
    {
        $data = $request->validate([
            'network_id' => ['required', 'integer', Rule::exists('networks', 'id')->whereNull('deleted_at')],
            'cidr' => ['required', 'string', 'max:18'],
            'gateway' => ['nullable', 'ipv4'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], attributes: __('asset.ipam.fields'));

        return [
            'network_id' => (int) $data['network_id'],
            'cidr' => $data['cidr'],
            'gateway' => $data['gateway'] ?? null,
            'description' => $data['description'] ?? null,
        ];
    }

    private function allow(Request $request): void
    {
        abort_unless($request->user()->can('ip-check.manage') && $request->user()->customer_id === null, 403);
    }
}
