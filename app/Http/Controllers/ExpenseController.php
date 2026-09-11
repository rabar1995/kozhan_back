<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Http\Requests\StoreExpenseRequest;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\AccountingService;
use App\Services\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ExpenseController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ExpenseService $expenses,
        private AccountingService $accounting,
    ) {
    }

    /**
     * Filterable expense list (category, date range).
     */
    public function index(Request $request): JsonResponse
    {
        $rows = Expense::with(['category:id,name', 'currency:id,code,symbol', 'paidFromAccount:id,name', 'expenseAccount:id,name', 'creator:id,name'])
            ->when($request->filled('category_id'), fn ($q) => $q->where('expense_category_id', $request->string('category_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->where('expense_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->where('expense_date', '<=', $request->date('date_to')))
            ->orderByDesc('expense_date')
            ->paginate($request->integer('per_page', 25));

        return $this->ok($rows);
    }

    /**
     * Register an operational expense (double-entry via ExpenseService).
     */
    public function store(StoreExpenseRequest $request): JsonResponse
    {
        try {
            $expense = $this->expenses->record($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok($expense->load(['category', 'currency', 'paidFromAccount', 'expenseAccount']), 'Expense recorded.', 201);
    }

    /**
     * List active expense categories.
     */
    public function categories(Request $request): JsonResponse
    {
        $rows = ExpenseCategory::where('is_active', true)
            ->orderBy('name')
            ->get();

        return $this->ok($rows);
    }

    /**
     * Void an expense (owner only) via reversal transaction.
     */
    public function void(string $id, Request $request): JsonResponse
    {
        $expense = Expense::findOrFail($id);

        if ($expense->is_void) {
            return $this->fail('Expense is already void.', 422);
        }

        try {
            $this->accounting->voidTransaction($expense->transaction_id, $request->user()->id, 'Expense voided');
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        $expense->forceFill(['is_void' => true])->save();

        return $this->ok($expense, 'Expense voided.');
    }
}
