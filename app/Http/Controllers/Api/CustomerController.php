<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::where('tenant_id', Auth::user()->tenant_id);

        if ($request->search) {
            $query->where('name', 'like', "%{$request->search}%")
                ->orWhere('phone', 'like', "%{$request->search}%");
        }

        return $query->withCount(['sales', 'returns'])
            ->paginate(20);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'phone' => 'nullable|string|unique:customers,phone,NULL,id,tenant_id,' . Auth::user()->tenant_id,
            'email' => 'nullable|email',
            'address' => 'nullable|string',
        ]);

        $data['tenant_id'] = Auth::user()->tenant_id;
        $customer = Customer::create($data);

        return response()->json($customer, 201);
    }

    public function show(Customer $customer)
    {
        $this->authorizeTenant($customer);
        return $customer->load(['sales.product', 'returns.sale']);
    }

    public function update(Request $request, Customer $customer)
    {
        $this->authorizeTenant($customer);

        $customer->update($request->validate([
            'name' => 'string',
            'phone' => 'nullable|string',
            'email' => 'nullable|email',
            'address' => 'nullable|string',
        ]));

        return $customer;
    }

    public function destroy(Customer $customer)
    {
        $this->authorizeTenant($customer);
        $customer->delete();
        return response()->json(null, 204);
    }

    protected function authorizeTenant($model)
    {
        if ($model->tenant_id !== Auth::user()->tenant_id) {
            abort(403);
        }
    }
}
