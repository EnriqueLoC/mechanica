<?php

namespace Tests\Unit;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use PHPUnit\Framework\TestCase;

class AppointmentStatusTest extends TestCase
{
    public function test_it_defines_expected_values(): void
    {
        $this->assertSame('pending', AppointmentStatus::Pending->value);
        $this->assertSame('confirmed', AppointmentStatus::Confirmed->value);
        $this->assertSame('cancelled', AppointmentStatus::Cancelled->value);
        $this->assertSame('completed', AppointmentStatus::Completed->value);
        $this->assertSame(['pending', 'confirmed', 'cancelled', 'completed'], AppointmentStatus::values());
    }

    public function test_pending_allowed_and_disallowed_transitions(): void
    {
        $this->assertTrue(AppointmentStatus::Pending->canTransitionTo(AppointmentStatus::Confirmed));
        $this->assertTrue(AppointmentStatus::Pending->canTransitionTo(AppointmentStatus::Cancelled));

        $this->assertFalse(AppointmentStatus::Pending->canTransitionTo(AppointmentStatus::Pending));
        $this->assertFalse(AppointmentStatus::Pending->canTransitionTo(AppointmentStatus::Completed));
    }

    public function test_confirmed_allowed_and_disallowed_transitions(): void
    {
        $this->assertTrue(AppointmentStatus::Confirmed->canTransitionTo(AppointmentStatus::Completed));

        $this->assertFalse(AppointmentStatus::Confirmed->canTransitionTo(AppointmentStatus::Pending));
        $this->assertFalse(AppointmentStatus::Confirmed->canTransitionTo(AppointmentStatus::Confirmed));
        $this->assertFalse(AppointmentStatus::Confirmed->canTransitionTo(AppointmentStatus::Cancelled));
    }

    public function test_cancelled_disallowed_transitions(): void
    {
        $this->assertFalse(AppointmentStatus::Cancelled->canTransitionTo(AppointmentStatus::Pending));
        $this->assertFalse(AppointmentStatus::Cancelled->canTransitionTo(AppointmentStatus::Confirmed));
        $this->assertFalse(AppointmentStatus::Cancelled->canTransitionTo(AppointmentStatus::Cancelled));
        $this->assertFalse(AppointmentStatus::Cancelled->canTransitionTo(AppointmentStatus::Completed));
    }

    public function test_completed_disallowed_transitions(): void
    {
        $this->assertFalse(AppointmentStatus::Completed->canTransitionTo(AppointmentStatus::Pending));
        $this->assertFalse(AppointmentStatus::Completed->canTransitionTo(AppointmentStatus::Confirmed));
        $this->assertFalse(AppointmentStatus::Completed->canTransitionTo(AppointmentStatus::Cancelled));
        $this->assertFalse(AppointmentStatus::Completed->canTransitionTo(AppointmentStatus::Completed));
    }

    public function test_appointment_model_can_transition_to_method(): void
    {
        $appointment = new Appointment(['status' => AppointmentStatus::Pending]);

        $this->assertTrue($appointment->canTransitionTo(AppointmentStatus::Confirmed));
        $this->assertTrue($appointment->canTransitionTo('confirmed'));
        $this->assertTrue($appointment->canTransitionTo('cancelled'));
        $this->assertFalse($appointment->canTransitionTo('completed'));
        $this->assertFalse($appointment->canTransitionTo('invalid_status'));
    }
}
