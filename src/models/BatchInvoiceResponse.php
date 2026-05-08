<?php

declare(strict_types=1);

namespace eseperio\verifactu\models;

/**
 * Model representing the aggregated response from a batch invoice submission or cancellation.
 */
class BatchInvoiceResponse extends Model
{
    /**
     * Individual InvoiceResponse objects returned for each record in the batch.
     * @var InvoiceResponse[]
     */
    public $responses = [];

    /**
     * Whether every response in the batch was successful.
     * @var bool
     */
    public $allSuccessful = false;

    /**
     * Total number of records sent in the batch.
     * @var int
     */
    public $totalRecordsSent = 0;

    /**
     * Number of successful responses.
     * @var int
     */
    public $totalSuccesses = 0;

    /**
     * Number of failed responses.
     * @var int
     */
    public $totalFailures = 0;

    /**
     * Aggregated error map (code => description) from all failed responses.
     * @var array<string, string>
     */
    public $errors = [];

    /**
     * Adds an InvoiceResponse and updates aggregated counters.
     *
     * @param int $recordCount Number of records represented by this response (defaults to 1).
     */
    public function addResponse(InvoiceResponse $response, int $recordCount = 1): void
    {
        $this->responses[] = $response;
        $this->totalRecordsSent += $recordCount;

        $responseErrors = $response->getErrors();
        if (empty($responseErrors)) {
            $this->totalSuccesses += $recordCount;
        } else {
            $this->totalFailures += $recordCount;
            $this->errors = array_replace($this->errors, $responseErrors);
        }

        $this->allSuccessful = $this->totalFailures === 0 && $this->totalRecordsSent > 0;
    }

    /**
     * Returns true only when the batch contains at least one response,
     * all responses succeeded, and no errors were recorded.
     */
    public function isFullySuccessful(): bool
    {
        return $this->allSuccessful && empty($this->errors);
    }

    /**
     * Validation rules for the batch response model.
     */
    public function rules(): array
    {
        return [
            [['responses', 'errors'], 'array'],
            [['totalRecordsSent', 'totalSuccesses', 'totalFailures'], 'integer'],
        ];
    }
}
