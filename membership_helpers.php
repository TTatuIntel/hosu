<?php
/**
 * HOSU Membership Helpers
 *
 * Calendar-year-end expiry rule (governance decision, Improvement Plan §6, §12):
 *   - All memberships expire on 31 December.
 *   - Late-joiner rule = ROLL FORWARD. Anyone joining on or after Oct 1
 *     gets their first term ending on 31 Dec of the following year.
 *   - Multi-year plans extend from the base end-of-year:
 *       1_year  -> 31 Dec of join year (or join_year+1 if late-joiner)
 *       2_years -> base + 1
 *       3_years -> base + 2
 *       lifetime -> 31 Dec 2099 (sentinel)
 */

if (!function_exists('hosuMembershipExpiry')) {
    /**
     * Calculate membership expiry date.
     *
     * @param string $period     One of '1_year','2_years','3_years','lifetime'
     * @param string|null $joinDate ISO date the term begins (defaults to today)
     * @param int $lateJoinerCutoffMonth  Month from which the roll-forward kicks in (default 10 = Oct)
     * @return string|null  'YYYY-12-31' or null if not applicable
     */
    function hosuMembershipExpiry(string $period, ?string $joinDate = null, int $lateJoinerCutoffMonth = 10): ?string
    {
        $ts = $joinDate ? strtotime($joinDate) : time();
        if ($ts === false) $ts = time();

        if ($period === 'lifetime') return '2099-12-31';

        $year  = (int)date('Y', $ts);
        $month = (int)date('n', $ts);
        $base  = ($month >= $lateJoinerCutoffMonth) ? $year + 1 : $year;

        $years = ['1_year' => 0, '2_years' => 1, '3_years' => 2];
        if (!isset($years[$period])) return null;

        $endYear = $base + $years[$period];
        return sprintf('%04d-12-31', $endYear);
    }
}

if (!function_exists('hosuMembershipStatus')) {
    /**
     * The one rule that matters (Improvement Plan §6):
     *   Active = approved + paid + not expired.
     *
     * @param array $member  Row from members table (must include approval_status, status, expiry_date)
     * @return string  One of: pending, needs_correction, approved_unpaid, active, expired, suspended, honorary, retired
     */
    function hosuMembershipStatus(array $member): string
    {
        $st = $member['status'] ?? 'pending';
        if (in_array($st, ['suspended', 'honorary', 'retired', 'rejected'], true)) return $st;

        $approval = $member['approval_status'] ?? 'pending';
        if ($approval === 'pending')          return 'pending';
        if ($approval === 'needs_correction') return 'needs_correction';
        if ($approval === 'rejected')         return 'rejected';

        $paid   = !empty($member['dues_paid_at']);
        $expiry = $member['expiry_date'] ?? null;

        if (!$paid) return 'approved_unpaid';
        if ($expiry && strtotime($expiry) < strtotime(date('Y-m-d'))) return 'expired';
        return 'active';
    }
}

if (!function_exists('hosuMembershipNumber')) {
    /**
     * Generate a stable membership number, e.g. HOSU-2026-0012.
     */
    function hosuMembershipNumber(int $memberId, ?string $joinDate = null): string
    {
        $year = $joinDate ? date('Y', strtotime($joinDate)) : date('Y');
        return sprintf('HOSU-%s-%04d', $year, $memberId);
    }
}
