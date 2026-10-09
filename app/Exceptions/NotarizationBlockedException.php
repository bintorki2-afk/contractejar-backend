<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * دفعة (هـ): التوثيق مرفوض لسبب يخص الدفع — code = payment_required | charge_pending.
 */
class NotarizationBlockedException extends ValidationException
{
    public string $reasonCode;

    public static function because(string $reasonCode, string $message): self
    {
        $exception = static::withMessages(['contract_status_id' => [$message]]);
        $exception->reasonCode = $reasonCode;

        return $exception;
    }

    /** @return array<string, mixed> */
    public function toResponseArray(): array
    {
        return [
            'message' => $this->getMessageText(),
            'code' => $this->reasonCode,
            'reason' => $this->reasonCode,
            'success' => false,
            'errors' => $this->errors(),
        ];
    }

    private function getMessageText(): string
    {
        $first = collect($this->errors())->flatten()->first();

        return is_string($first) && $first !== '' ? $first : $this->getMessage();
    }
}
