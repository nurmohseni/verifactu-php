<?php

declare(strict_types=1);

namespace eseperio\verifactu\tests\Unit\Models;

use eseperio\verifactu\models\BatchInvoiceResponse;
use eseperio\verifactu\models\InvoiceResponse;
use PHPUnit\Framework\TestCase;

class BatchInvoiceResponseTest extends TestCase
{
    /**
     * Test that BatchInvoiceResponse aggregates mixed success and failure responses.
     */
    public function testAggregatesSuccessAndFailure(): void
    {
        $batch = new BatchInvoiceResponse();

        $success = new InvoiceResponse();
        $success->submissionStatus = InvoiceResponse::STATUS_OK;
        $success->lineResponses = [];

        $failure = new InvoiceResponse();
        $failure->submissionStatus = 'Incorrecto';
        $failure->lineResponses = [
            ['CodigoErrorRegistro' => '1100', 'DescripcionErrorRegistro' => 'NIF no valido'],
        ];

        $batch->addResponse($success);
        $batch->addResponse($failure);

        $this->assertCount(2, $batch->responses);
        $this->assertSame(2, $batch->totalRecordsSent);
        $this->assertSame(1, $batch->totalSuccesses);
        $this->assertSame(1, $batch->totalFailures);
        $this->assertFalse($batch->allSuccessful);
        $this->assertSame(['1100' => 'NIF no valido'], $batch->errors);
        $this->assertFalse($batch->isFullySuccessful());
    }

    /**
     * Test that a batch with only successful responses is fully successful.
     */
    public function testFullySuccessfulWhenAllSuccess(): void
    {
        $batch = new BatchInvoiceResponse();

        $success1 = new InvoiceResponse();
        $success1->submissionStatus = InvoiceResponse::STATUS_OK;
        $success1->lineResponses = [];

        $success2 = new InvoiceResponse();
        $success2->submissionStatus = InvoiceResponse::STATUS_OK;
        $success2->lineResponses = [];

        $batch->addResponse($success1);
        $batch->addResponse($success2);

        $this->assertSame(2, $batch->totalRecordsSent);
        $this->assertSame(2, $batch->totalSuccesses);
        $this->assertSame(0, $batch->totalFailures);
        $this->assertTrue($batch->allSuccessful);
        $this->assertEmpty($batch->errors);
        $this->assertTrue($batch->isFullySuccessful());
    }

    /**
     * Test that an empty batch is not fully successful and has zero totals.
     */
    public function testEmptyBatchIsNotFullySuccessful(): void
    {
        $batch = new BatchInvoiceResponse();

        $this->assertSame(0, $batch->totalRecordsSent);
        $this->assertSame(0, $batch->totalSuccesses);
        $this->assertSame(0, $batch->totalFailures);
        $this->assertFalse($batch->allSuccessful);
        $this->assertEmpty($batch->errors);
        $this->assertFalse($batch->isFullySuccessful());
    }
}
