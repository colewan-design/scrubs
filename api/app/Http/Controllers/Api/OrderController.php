<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Orders\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Customer-facing order history (§8) and the order lookup a guest needs after
 * checking out without an account.
 */
class OrderController extends Controller
{
    public function __construct(protected OrderService $orders) {}

    /** The signed-in customer's own orders, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            // The list draws each order's first few products and offers to
            // reorder it, so the lines carry their live product; shipments are
            // what put a tracking number next to a shipped order.
            ->with(['items.variant.product.images', 'shipments'])
            ->latest('placed_at')
            ->paginate(20);

        return OrderResource::collection($orders);
    }

    /**
     * One order.
     *
     * Order numbers are sequential and therefore guessable, so ownership is
     * always proved: either the order belongs to the signed-in user, or the
     * caller supplies the email address the order was placed with.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();

        $ownsIt = $user && $order->user_id === $user->id;
        $knowsEmail = $request->filled('email')
            && hash_equals(strtolower($order->email), strtolower((string) $request->query('email')));

        abort_unless($ownsIt || $knowsEmail, 404);

        return response()->json([
            'order' => OrderResource::make($order->load([
                'items.variant.product.images', 'taxes', 'addresses', 'shipments', 'statusHistory',
            ]))->toArray($request),
        ]);
    }

    /** A customer may cancel their own order while nothing has shipped (§7). */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        abort_unless($request->user() && $order->user_id === $request->user()->id, 404);

        try {
            $order = $this->orders->cancel($order, 'Cancelled by customer.', $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'order' => OrderResource::make($order->load([
                'items.variant.product.images', 'taxes', 'addresses', 'statusHistory',
            ]))->toArray($request),
        ]);
    }
}
