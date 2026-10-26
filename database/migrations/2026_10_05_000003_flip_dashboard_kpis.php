<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Flip the debtor/creditor logic in fn_dashboard_kpis because the
     * agent_wallet is now a liability: positive net_balance means the
     * office owes the agent (creditor), negative means the agent owes
     * the office (debtor).
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.fn_dashboard_kpis(p_office_id uuid, p_date date DEFAULT CURRENT_DATE)
            RETURNS json
            LANGUAGE plpgsql
            AS $function$
            DECLARE
                result JSON;
            BEGIN
                SELECT JSON_BUILD_OBJECT(
                    'today', JSON_BUILD_OBJECT(
                        'incoming_count',
                            (SELECT COUNT(*) FROM remittances
                             WHERE office_id = p_office_id AND direction = 'incoming'
                               AND created_at::DATE = p_date AND status != 'cancelled'),
                        'outgoing_count',
                            (SELECT COUNT(*) FROM remittances
                             WHERE office_id = p_office_id AND direction = 'outgoing'
                               AND created_at::DATE = p_date AND status != 'cancelled'),
                        'pending_count',
                            (SELECT COUNT(*) FROM remittances
                             WHERE office_id = p_office_id AND status = 'pending'),
                        'completed_today',
                            (SELECT COUNT(*) FROM remittances
                             WHERE office_id = p_office_id AND status = 'completed'
                               AND completed_at::DATE = p_date),
                        'commission_earned_today',
                            (SELECT COALESCE(SUM(commission_amount), 0) FROM remittances
                             WHERE office_id = p_office_id AND commission_type = 'earned'
                               AND created_at::DATE = p_date AND status != 'cancelled'),
                        'commission_paid_today',
                            (SELECT COALESCE(SUM(commission_amount), 0) FROM remittances
                             WHERE office_id = p_office_id AND commission_type = 'paid'
                               AND created_at::DATE = p_date AND status != 'cancelled')
                    ),
                    'agents', JSON_BUILD_OBJECT(
                        'total_debtors',
                            (SELECT COUNT(*) FROM agents
                             WHERE office_id = p_office_id AND net_balance < 0 AND is_active),
                        'total_creditors',
                            (SELECT COUNT(*) FROM agents
                             WHERE office_id = p_office_id AND net_balance > 0 AND is_active),
                        'total_receivable',
                            (SELECT COALESCE(SUM(ABS(net_balance)), 0) FROM agents
                             WHERE office_id = p_office_id AND net_balance < 0),
                        'total_payable',
                            (SELECT COALESCE(SUM(net_balance), 0) FROM agents
                             WHERE office_id = p_office_id AND net_balance > 0)
                    ),
                    'monthly', JSON_BUILD_OBJECT(
                        'total_revenue',
                            (SELECT COALESCE(SUM(
                                CASE WHEN je.entry_type = 'credit' THEN je.amount ELSE -je.amount END
                            ), 0)
                            FROM journal_entries je
                            JOIN accounts a ON a.id = je.account_id
                            JOIN account_types at ON at.id = a.account_type_id
                            JOIN transactions t ON t.id = je.transaction_id
                            WHERE a.office_id = p_office_id
                              AND at.category = 'revenue'
                              AND t.is_void = FALSE
                              AND je.created_at >= DATE_TRUNC('month', p_date::TIMESTAMP)
                              AND je.created_at < DATE_TRUNC('month', p_date::TIMESTAMP) + INTERVAL '1 month'),
                        'total_expenses',
                            (SELECT COALESCE(SUM(
                                CASE WHEN je.entry_type = 'debit' THEN je.amount ELSE -je.amount END
                            ), 0)
                            FROM journal_entries je
                            JOIN accounts a ON a.id = je.account_id
                            JOIN account_types at ON at.id = a.account_type_id
                            JOIN transactions t ON t.id = je.transaction_id
                            WHERE a.office_id = p_office_id
                              AND at.category = 'expense'
                              AND t.is_void = FALSE
                              AND je.created_at >= DATE_TRUNC('month', p_date::TIMESTAMP)
                              AND je.created_at < DATE_TRUNC('month', p_date::TIMESTAMP) + INTERVAL '1 month')
                    )
                ) INTO result;

                RETURN result;
            END;
            $function$;
        SQL);
    }

    public function down(): void
    {
        // One-way update; previous function definition is in earlier migrations.
    }
};
