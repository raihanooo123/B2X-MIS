<?php

namespace App\Http\Requests\Api\V1\Warehouse;

/**
 * POST …/shipments/{id}/substitutions — 05.5 §5.3. Names the line by its
 * allocated batch, the batch actually taken, and why.
 */
class SubstituteBatchRequest extends PickLineRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'batch_code' => ['required', 'string', 'max:64'],
            'new_batch_code' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
    }

    public function newBatchCode(): string
    {
        return trim((string) $this->validated('new_batch_code'));
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
