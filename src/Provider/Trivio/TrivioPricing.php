<?php

declare(strict_types=1);

namespace App\Provider\Trivio;

/** Prices are calculated once per service, so its fees are never repeated per passenger. */
final class TrivioPricing
{
    /** @return array<string, array{amount: ?float, ticket_amount: ?float, service_fee: float}> */
    public static function tickets(array $tickets, array $service, string $ticketPrefix, string $servicePrefix): array
    {
        if ($tickets === []) {
            return [];
        }
        usort($tickets, static fn (array $left, array $right): int =>
            strcmp((string) $left[$ticketPrefix . 'id'], (string) $right[$ticketPrefix . 'id'])
        );
        $prices = [];
        $fees = [];
        foreach ($tickets as $index => $ticket) {
            $cost = self::cents($ticket[$ticketPrefix . 'totalcost'] ?? $ticket[$ticketPrefix . 'total_cost'] ?? null);
            $fare = self::cents($ticket[$ticketPrefix . 'ticketfare'] ?? $ticket[$ticketPrefix . 'fare'] ?? null);
            $tax = self::cents($ticket[$ticketPrefix . 'tickettax'] ?? $ticket[$ticketPrefix . 'tax'] ?? null);
            $fee = self::cents($ticket[$ticketPrefix . 'fee'] ?? null) ?? 0;
            $providerFee = self::cents($ticket[$ticketPrefix . 'provider_fee'] ?? null) ?? 0;
            $fees[$index] = $fee + $providerFee;
            // Prefer the component total when it proves a separate fee was omitted.
            $prices[$index] = $fare !== null && $tax !== null
                ? max($cost ?? 0, $fare + $tax + $fees[$index])
                : $cost;
        }
        $weights = array_map(static fn (?int $price): int => max(0, $price ?? 0), $prices);
        $serviceFee = (self::cents($service[$servicePrefix . 'fee'] ?? null) ?? 0)
            + (self::cents($service[$servicePrefix . 'provider_fee'] ?? null) ?? 0);
        $missingFee = max(0, $serviceFee - array_sum($fees));
        $feeShares = self::allocate($missingFee, $weights);
        foreach ($fees as $index => $fee) {
            $fees[$index] += $feeShares[$index];
        }
        $serviceTotal = self::cents($service['total_price'] ?? null);
        if ($serviceTotal !== null && !in_array(null, $prices, true)) {
            // total_price is the documented final price for the entire service.
            // Allocate only the difference, including the final kopeck, once.
            $difference = self::allocate($serviceTotal - array_sum($prices), $weights);
            foreach ($prices as $index => $price) {
                $prices[$index] = $price + $difference[$index];
            }
        } elseif ($serviceTotal !== null && count($tickets) === 1) {
            $prices[array_key_first($prices)] = $serviceTotal;
        } else {
            // Without a final service total, only add fees to a known fare+tax base.
            // An ambiguous totalcost alone must not have the same fee added twice.
            foreach ($tickets as $index => $ticket) {
                $fare = self::cents($ticket[$ticketPrefix . 'ticketfare'] ?? $ticket[$ticketPrefix . 'fare'] ?? null);
                $tax = self::cents($ticket[$ticketPrefix . 'tickettax'] ?? $ticket[$ticketPrefix . 'tax'] ?? null);
                if ($fare !== null && $tax !== null) {
                    $prices[$index] = max($prices[$index] ?? 0, $fare + $tax + $fees[$index]);
                }
            }
        }
        $result = [];
        foreach ($tickets as $index => $ticket) {
            $price = $prices[$index];
            $result[(string) $ticket[$ticketPrefix . 'id']] = [
                'amount' => $price !== null ? $price / 100 : null,
                'ticket_amount' => $price !== null ? max(0, $price - $fees[$index]) / 100 : null,
                'service_fee' => $fees[$index] / 100,
            ];
        }
        return $result;
    }

    private static function cents(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value * 100) : null;
    }

    /** @return array<int, int> */
    private static function allocate(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum === 0) {
            $weights = array_fill_keys(array_keys($weights), 1);
            $sum = count($weights);
        }
        $result = [];
        $assigned = 0;
        $last = array_key_last($weights);
        foreach ($weights as $index => $weight) {
            $share = $index === $last ? $total - $assigned : (int) round($total * $weight / $sum);
            $result[$index] = $share;
            $assigned += $share;
        }
        return $result;
    }
}
