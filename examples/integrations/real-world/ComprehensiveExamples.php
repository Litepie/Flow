<?php

namespace App\Examples\Integrations\RealWorld;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Litepie\Flow\Events\WorkflowTransitioned;
use Litepie\Actions\BaseAction;
use Litepie\Actions\Contracts\ActionResult;
use Carbon\Carbon;

/**
 * Real-World Integration Examples with Litepie Flow
 * 
 * This file contains comprehensive real-world scenarios demonstrating
 * how to integrate Litepie Flow with various systems in production environments.
 */

// 1. E-commerce Order Processing Integration
class EcommerceOrderProcessingIntegration
{
    private array $integrations;

    public function __construct()
    {
        $this->integrations = [
            'payment' => new PaymentGatewayIntegration(),
            'inventory' => new InventoryManagementIntegration(),
            'shipping' => new ShippingIntegration(),
            'crm' => new CRMIntegration(),
            'analytics' => new AnalyticsIntegration(),
            'notifications' => new NotificationIntegration(),
        ];
    }

    public function handleOrderTransition(WorkflowTransitioned $event): void
    {
        $order = $event->getSubject();
        $fromState = $event->getFromState();
        $toState = $event->getToState();

        Log::info('Processing order state transition', [
            'order_id' => $order->id,
            'from_state' => $fromState,
            'to_state' => $toState,
        ]);

        // Execute integrations based on state transition
        match ($toState) {
            'payment_pending' => $this->handlePaymentPending($order),
            'paid' => $this->handleOrderPaid($order),
            'processing' => $this->handleOrderProcessing($order),
            'shipped' => $this->handleOrderShipped($order),
            'delivered' => $this->handleOrderDelivered($order),
            'cancelled' => $this->handleOrderCancelled($order),
            'refunded' => $this->handleOrderRefunded($order),
            default => $this->handleUnknownState($order, $toState),
        };
    }

    private function handlePaymentPending($order): void
    {
        // Create payment record
        $this->integrations['payment']->createPaymentRecord($order);
        
        // Reserve inventory
        $this->integrations['inventory']->reserveInventory($order);
        
        // Send payment reminder notification
        $this->integrations['notifications']->sendPaymentReminder($order);
        
        // Track analytics event
        $this->integrations['analytics']->trackEvent('order_payment_pending', [
            'order_id' => $order->id,
            'amount' => $order->total_amount,
            'customer_id' => $order->customer_id,
        ]);
    }

    private function handleOrderPaid($order): void
    {
        // Process payment
        $paymentResult = $this->integrations['payment']->processPayment($order);
        
        if ($paymentResult['success']) {
            // Confirm inventory reservation
            $this->integrations['inventory']->confirmReservation($order);
            
            // Update CRM with purchase
            $this->integrations['crm']->recordPurchase($order);
            
            // Send order confirmation
            $this->integrations['notifications']->sendOrderConfirmation($order);
            
            // Track conversion
            $this->integrations['analytics']->trackConversion($order);
            
            // Automatically move to processing
            $order->transitionTo('processing');
        } else {
            // Payment failed, handle accordingly
            $order->transitionTo('payment_failed', [
                'payment_error' => $paymentResult['error'],
            ]);
        }
    }

    private function handleOrderProcessing($order): void
    {
        // Allocate inventory
        $allocationResult = $this->integrations['inventory']->allocateInventory($order);
        
        if ($allocationResult['success']) {
            // Calculate shipping
            $shippingResult = $this->integrations['shipping']->calculateShipping($order);
            
            if ($shippingResult['success']) {
                // Create shipping label
                $this->integrations['shipping']->createShippingLabel($order);
                
                // Update CRM with shipping info
                $this->integrations['crm']->updateShippingInfo($order, $shippingResult);
                
                // Send processing notification
                $this->integrations['notifications']->sendProcessingNotification($order);
                
                // Track fulfillment start
                $this->integrations['analytics']->trackEvent('fulfillment_started', [
                    'order_id' => $order->id,
                    'processing_time' => now()->diffInHours($order->created_at),
                ]);
            } else {
                // Shipping calculation failed
                $this->integrations['notifications']->sendShippingIssueAlert($order, $shippingResult['error']);
            }
        } else {
            // Inventory allocation failed
            $order->transitionTo('inventory_shortage', [
                'shortage_details' => $allocationResult['shortage_details'],
            ]);
        }
    }

    private function handleOrderShipped($order): void
    {
        // Generate tracking number
        $trackingResult = $this->integrations['shipping']->generateTracking($order);
        
        if ($trackingResult['success']) {
            // Update order with tracking info
            $order->update([
                'tracking_number' => $trackingResult['tracking_number'],
                'shipped_at' => now(),
            ]);
            
            // Send shipping notification
            $this->integrations['notifications']->sendShippingNotification($order, $trackingResult);
            
            // Update CRM with tracking
            $this->integrations['crm']->updateTrackingInfo($order, $trackingResult);
            
            // Track shipment
            $this->integrations['analytics']->trackEvent('order_shipped', [
                'order_id' => $order->id,
                'tracking_number' => $trackingResult['tracking_number'],
                'fulfillment_time' => now()->diffInHours($order->created_at),
            ]);
            
            // Schedule delivery tracking
            $this->scheduleDeliveryTracking($order);
        }
    }

    private function handleOrderDelivered($order): void
    {
        // Confirm delivery
        $this->integrations['shipping']->confirmDelivery($order);
        
        // Release inventory hold
        $this->integrations['inventory']->releaseInventoryHold($order);
        
        // Update CRM with delivery
        $this->integrations['crm']->recordDelivery($order);
        
        // Send delivery confirmation
        $this->integrations['notifications']->sendDeliveryConfirmation($order);
        
        // Request review
        $this->integrations['notifications']->requestReview($order);
        
        // Track delivery
        $this->integrations['analytics']->trackEvent('order_delivered', [
            'order_id' => $order->id,
            'total_time' => now()->diffInHours($order->created_at),
            'delivery_time' => now()->diffInHours($order->shipped_at),
        ]);
        
        // Update customer lifetime value
        $this->integrations['crm']->updateCustomerLifetimeValue($order->customer_id);
    }

    private function handleOrderCancelled($order): void
    {
        // Process refund if payment was made
        if ($order->payment_status === 'completed') {
            $this->integrations['payment']->processRefund($order);
        }
        
        // Release inventory
        $this->integrations['inventory']->releaseReservation($order);
        
        // Cancel shipping if applicable
        if ($order->tracking_number) {
            $this->integrations['shipping']->cancelShipment($order);
        }
        
        // Update CRM
        $this->integrations['crm']->recordCancellation($order);
        
        // Send cancellation notification
        $this->integrations['notifications']->sendCancellationNotification($order);
        
        // Track cancellation
        $this->integrations['analytics']->trackEvent('order_cancelled', [
            'order_id' => $order->id,
            'cancellation_reason' => $order->cancellation_reason,
            'time_to_cancellation' => now()->diffInHours($order->created_at),
        ]);
    }

    private function handleOrderRefunded($order): void
    {
        // Process refund
        $refundResult = $this->integrations['payment']->processRefund($order);
        
        if ($refundResult['success']) {
            // Update CRM
            $this->integrations['crm']->recordRefund($order, $refundResult);
            
            // Send refund notification
            $this->integrations['notifications']->sendRefundNotification($order, $refundResult);
            
            // Track refund
            $this->integrations['analytics']->trackEvent('order_refunded', [
                'order_id' => $order->id,
                'refund_amount' => $refundResult['amount'],
                'refund_reason' => $order->refund_reason,
            ]);
        }
    }

    private function handleUnknownState($order, string $state): void
    {
        Log::warning('Unknown order state encountered', [
            'order_id' => $order->id,
            'state' => $state,
        ]);
        
        // Send alert to administrators
        $this->integrations['notifications']->sendAdminAlert(
            'Unknown Order State',
            "Order {$order->id} transitioned to unknown state: {$state}"
        );
    }

    private function scheduleDeliveryTracking($order): void
    {
        // Schedule periodic delivery tracking checks
        DeliveryTrackingJob::dispatch($order->id)
            ->delay(now()->addHours(24));
    }
}

// 2. Healthcare Patient Journey Integration
class HealthcarePatientJourneyIntegration
{
    private array $systems;

    public function __construct()
    {
        $this->systems = [
            'ehr' => new EHRIntegration(), // Electronic Health Records
            'pacs' => new PACSIntegration(), // Picture Archiving and Communication System
            'lab' => new LabIntegration(),
            'pharmacy' => new PharmacyIntegration(),
            'billing' => new MedicalBillingIntegration(),
            'scheduler' => new AppointmentSchedulerIntegration(),
            'notifications' => new PatientNotificationIntegration(),
        ];
    }

    public function handlePatientTransition(WorkflowTransitioned $event): void
    {
        $patient = $event->getSubject();
        $toState = $event->getToState();
        
        // Ensure HIPAA compliance logging
        $this->logHIPAACompliantEvent($patient, $event);

        match ($toState) {
            'registered' => $this->handlePatientRegistration($patient),
            'appointment_scheduled' => $this->handleAppointmentScheduled($patient),
            'checked_in' => $this->handlePatientCheckIn($patient),
            'in_consultation' => $this->handleConsultationStart($patient),
            'lab_ordered' => $this->handleLabOrdered($patient),
            'imaging_ordered' => $this->handleImagingOrdered($patient),
            'prescription_issued' => $this->handlePrescriptionIssued($patient),
            'treatment_completed' => $this->handleTreatmentCompleted($patient),
            'discharged' => $this->handlePatientDischarged($patient),
            default => $this->handleUnknownPatientState($patient, $toState),
        };
    }

    private function handlePatientRegistration($patient): void
    {
        // Create EHR record
        $ehrResult = $this->systems['ehr']->createPatientRecord($patient);
        
        if ($ehrResult['success']) {
            // Link with scheduling system
            $this->systems['scheduler']->createPatientProfile($patient, $ehrResult['ehr_id']);
            
            // Set up billing profile
            $this->systems['billing']->createBillingProfile($patient);
            
            // Send welcome notification
            $this->systems['notifications']->sendWelcomeMessage($patient);
            
            // Verify insurance
            $this->systems['billing']->verifyInsurance($patient);
        }
    }

    private function handleAppointmentScheduled($patient): void
    {
        $appointment = $patient->getCurrentAppointment();
        
        // Update EHR with appointment
        $this->systems['ehr']->recordAppointment($patient, $appointment);
        
        // Send appointment confirmation
        $this->systems['notifications']->sendAppointmentConfirmation($patient, $appointment);
        
        // Send pre-visit instructions
        $this->systems['notifications']->sendPreVisitInstructions($patient, $appointment);
        
        // Schedule reminder notifications
        $this->scheduleAppointmentReminders($patient, $appointment);
    }

    private function handlePatientCheckIn($patient): void
    {
        $appointment = $patient->getCurrentAppointment();
        
        // Update appointment status
        $this->systems['scheduler']->checkInPatient($patient, $appointment);
        
        // Update EHR with check-in time
        $this->systems['ehr']->recordCheckIn($patient, $appointment);
        
        // Notify care team
        $this->systems['notifications']->notifyCareTeam($patient, $appointment);
        
        // Prepare visit documentation
        $this->systems['ehr']->prepareVisitDocumentation($patient, $appointment);
    }

    private function handleConsultationStart($patient): void
    {
        $appointment = $patient->getCurrentAppointment();
        
        // Create consultation record
        $consultationId = $this->systems['ehr']->startConsultation($patient, $appointment);
        
        // Load patient history
        $patientHistory = $this->systems['ehr']->getPatientHistory($patient);
        
        // Load recent lab results
        $labResults = $this->systems['lab']->getRecentResults($patient);
        
        // Load imaging studies
        $imagingStudies = $this->systems['pacs']->getPatientStudies($patient);
        
        // Prepare consultation dashboard
        $this->prepareConsultationDashboard($patient, $consultationId, [
            'history' => $patientHistory,
            'lab_results' => $labResults,
            'imaging' => $imagingStudies,
        ]);
    }

    private function handleLabOrdered($patient): void
    {
        $labOrders = $patient->getPendingLabOrders();
        
        foreach ($labOrders as $order) {
            // Submit to lab system
            $labResult = $this->systems['lab']->submitOrder($patient, $order);
            
            if ($labResult['success']) {
                // Update EHR with lab order
                $this->systems['ehr']->recordLabOrder($patient, $order, $labResult['lab_id']);
                
                // Send patient instructions
                $this->systems['notifications']->sendLabInstructions($patient, $order);
                
                // Schedule result follow-up
                $this->scheduleLabResultFollowUp($patient, $order, $labResult['estimated_completion']);
            }
        }
    }

    private function handleImagingOrdered($patient): void
    {
        $imagingOrders = $patient->getPendingImagingOrders();
        
        foreach ($imagingOrders as $order) {
            // Schedule imaging appointment
            $imagingResult = $this->systems['pacs']->scheduleImaging($patient, $order);
            
            if ($imagingResult['success']) {
                // Update EHR
                $this->systems['ehr']->recordImagingOrder($patient, $order, $imagingResult);
                
                // Send prep instructions
                $this->systems['notifications']->sendImagingPrep($patient, $order);
                
                // Update scheduler
                $this->systems['scheduler']->addImagingAppointment($patient, $imagingResult);
            }
        }
    }

    private function handlePrescriptionIssued($patient): void
    {
        $prescriptions = $patient->getNewPrescriptions();
        
        foreach ($prescriptions as $prescription) {
            // Send to pharmacy
            $pharmacyResult = $this->systems['pharmacy']->submitPrescription($patient, $prescription);
            
            if ($pharmacyResult['success']) {
                // Update EHR
                $this->systems['ehr']->recordPrescription($patient, $prescription, $pharmacyResult);
                
                // Check for drug interactions
                $interactionCheck = $this->systems['pharmacy']->checkDrugInteractions($patient, $prescription);
                
                if ($interactionCheck['has_interactions']) {
                    // Alert prescriber
                    $this->systems['notifications']->alertDrugInteraction(
                        $prescription->prescriber,
                        $patient,
                        $interactionCheck
                    );
                }
                
                // Send patient medication instructions
                $this->systems['notifications']->sendMedicationInstructions($patient, $prescription);
            }
        }
    }

    private function handleTreatmentCompleted($patient): void
    {
        $treatment = $patient->getCurrentTreatment();
        
        // Record treatment completion in EHR
        $this->systems['ehr']->recordTreatmentCompletion($patient, $treatment);
        
        // Generate billing codes
        $billingCodes = $this->systems['billing']->generateBillingCodes($patient, $treatment);
        
        // Submit insurance claim
        $this->systems['billing']->submitInsuranceClaim($patient, $billingCodes);
        
        // Schedule follow-up if needed
        if ($treatment->requires_followup) {
            $this->systems['scheduler']->scheduleFollowUp($patient, $treatment);
        }
        
        // Send care summary
        $this->systems['notifications']->sendCareSummary($patient, $treatment);
    }

    private function handlePatientDischarged($patient): void
    {
        // Complete EHR documentation
        $this->systems['ehr']->completeVisitDocumentation($patient);
        
        // Generate discharge summary
        $dischargeSummary = $this->systems['ehr']->generateDischargeSummary($patient);
        
        // Send to patient portal
        $this->systems['notifications']->sendDischargeSummary($patient, $dischargeSummary);
        
        // Send satisfaction survey
        $this->systems['notifications']->sendSatisfactionSurvey($patient);
        
        // Update care plan
        $this->systems['ehr']->updateCarePlan($patient);
        
        // Schedule follow-up communications
        $this->scheduleFollowUpCommunications($patient);
    }

    private function logHIPAACompliantEvent($patient, WorkflowTransitioned $event): void
    {
        // Log with proper HIPAA compliance
        DB::table('hipaa_audit_log')->insert([
            'patient_id' => $patient->id,
            'user_id' => auth()->id(),
            'action' => 'state_transition',
            'from_state' => $event->getFromState(),
            'to_state' => $event->getToState(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => now(),
        ]);
    }

    private function prepareConsultationDashboard($patient, $consultationId, array $data): void
    {
        // Aggregate all patient data for consultation
        Cache::put("consultation_dashboard:{$consultationId}", [
            'patient_id' => $patient->id,
            'history' => $data['history'],
            'lab_results' => $data['lab_results'],
            'imaging' => $data['imaging'],
            'current_medications' => $patient->getCurrentMedications(),
            'allergies' => $patient->getAllergies(),
            'vital_signs' => $patient->getLatestVitalSigns(),
        ], 3600); // 1 hour
    }

    private function scheduleAppointmentReminders($patient, $appointment): void
    {
        // 24 hours before
        PatientReminderJob::dispatch($patient, $appointment, '24_hour')
            ->delay($appointment->scheduled_at->subDay());
        
        // 2 hours before
        PatientReminderJob::dispatch($patient, $appointment, '2_hour')
            ->delay($appointment->scheduled_at->subHours(2));
    }

    private function scheduleLabResultFollowUp($patient, $order, $estimatedCompletion): void
    {
        LabResultFollowUpJob::dispatch($patient, $order)
            ->delay($estimatedCompletion->addHours(2));
    }

    private function scheduleFollowUpCommunications($patient): void
    {
        // 24 hours post-discharge
        PatientFollowUpJob::dispatch($patient, 'post_discharge_24h')
            ->delay(now()->addDay());
        
        // 1 week post-discharge
        PatientFollowUpJob::dispatch($patient, 'post_discharge_1w')
            ->delay(now()->addWeek());
    }

    private function handleUnknownPatientState($patient, string $state): void
    {
        Log::error('Unknown patient state encountered', [
            'patient_id' => $patient->id,
            'state' => $state,
        ]);
        
        // Alert care team
        $this->systems['notifications']->alertCareTeam(
            "Unknown patient state: {$state} for patient {$patient->id}"
        );
    }
}

// 3. Manufacturing Process Integration
class ManufacturingProcessIntegration
{
    private array $systems;

    public function __construct()
    {
        $this->systems = [
            'erp' => new ERPIntegration(),
            'mes' => new MESIntegration(), // Manufacturing Execution System
            'scada' => new SCADAIntegration(), // Supervisory Control and Data Acquisition
            'qms' => new QMSIntegration(), // Quality Management System
            'wms' => new WMSIntegration(), // Warehouse Management System
            'iot' => new IoTIntegration(),
            'maintenance' => new MaintenanceIntegration(),
        ];
    }

    public function handleProductionTransition(WorkflowTransitioned $event): void
    {
        $workOrder = $event->getSubject();
        $toState = $event->getToState();
        
        match ($toState) {
            'production_scheduled' => $this->handleProductionScheduled($workOrder),
            'raw_materials_allocated' => $this->handleRawMaterialsAllocated($workOrder),
            'production_started' => $this->handleProductionStarted($workOrder),
            'in_production' => $this->handleInProduction($workOrder),
            'quality_check' => $this->handleQualityCheck($workOrder),
            'production_completed' => $this->handleProductionCompleted($workOrder),
            'packaging' => $this->handlePackaging($workOrder),
            'ready_for_shipment' => $this->handleReadyForShipment($workOrder),
            default => $this->handleUnknownProductionState($workOrder, $toState),
        };
    }

    private function handleProductionScheduled($workOrder): void
    {
        // Update ERP with production schedule
        $this->systems['erp']->updateProductionSchedule($workOrder);
        
        // Reserve raw materials
        $reservationResult = $this->systems['wms']->reserveRawMaterials($workOrder);
        
        if ($reservationResult['success']) {
            // Update MES with work order
            $this->systems['mes']->createWorkOrder($workOrder);
            
            // Check equipment availability
            $equipmentCheck = $this->systems['maintenance']->checkEquipmentAvailability($workOrder);
            
            if (!$equipmentCheck['available']) {
                // Schedule maintenance if needed
                $this->systems['maintenance']->scheduleMaintenance($equipmentCheck['equipment']);
            }
        }
    }

    private function handleRawMaterialsAllocated($workOrder): void
    {
        // Move materials to production line
        $this->systems['wms']->moveToProduction($workOrder);
        
        // Initialize IoT monitoring
        $this->systems['iot']->initializeProductionMonitoring($workOrder);
        
        // Set up quality checkpoints
        $this->systems['qms']->setupQualityCheckpoints($workOrder);
        
        // Ready for production start
        $workOrder->transitionTo('ready_for_production');
    }

    private function handleProductionStarted($workOrder): void
    {
        // Start MES tracking
        $this->systems['mes']->startProduction($workOrder);
        
        // Initialize SCADA monitoring
        $this->systems['scada']->initializeMonitoring($workOrder);
        
        // Start IoT data collection
        $this->systems['iot']->startDataCollection($workOrder);
        
        // Create production log
        $this->createProductionLog($workOrder, 'started');
        
        // Monitor for completion
        $this->scheduleProductionMonitoring($workOrder);
    }

    private function handleInProduction($workOrder): void
    {
        // Collect real-time production data
        $productionData = $this->systems['iot']->getProductionData($workOrder);
        
        // Update ERP with progress
        $this->systems['erp']->updateProductionProgress($workOrder, $productionData);
        
        // Check for quality issues
        $qualityData = $this->systems['qms']->checkRealTimeQuality($workOrder);
        
        if ($qualityData['issues_detected']) {
            // Alert quality team
            $this->alertQualityIssues($workOrder, $qualityData);
        }
        
        // Monitor equipment performance
        $equipmentData = $this->systems['scada']->getEquipmentData($workOrder);
        
        if ($equipmentData['maintenance_required']) {
            // Schedule immediate maintenance
            $this->systems['maintenance']->scheduleImmediateMaintenance($equipmentData);
        }
    }

    private function handleQualityCheck($workOrder): void
    {
        // Perform quality inspection
        $qualityResult = $this->systems['qms']->performQualityInspection($workOrder);
        
        if ($qualityResult['passed']) {
            // Quality approved, move to completion
            $workOrder->transitionTo('production_completed', [
                'quality_report' => $qualityResult['report'],
            ]);
        } else {
            // Quality failed, determine action
            if ($qualityResult['reworkable']) {
                $workOrder->transitionTo('rework_required', [
                    'quality_issues' => $qualityResult['issues'],
                ]);
            } else {
                $workOrder->transitionTo('quality_rejected', [
                    'rejection_reasons' => $qualityResult['rejection_reasons'],
                ]);
            }
        }
    }

    private function handleProductionCompleted($workOrder): void
    {
        // Finalize production in MES
        $this->systems['mes']->completeProduction($workOrder);
        
        // Update inventory
        $this->systems['wms']->updateFinishedGoodsInventory($workOrder);
        
        // Generate production report
        $productionReport = $this->generateProductionReport($workOrder);
        
        // Update ERP with completion
        $this->systems['erp']->recordProductionCompletion($workOrder, $productionReport);
        
        // Calculate production costs
        $this->systems['erp']->calculateProductionCosts($workOrder);
        
        // Move to packaging
        $workOrder->transitionTo('packaging');
    }

    private function handlePackaging($workOrder): void
    {
        // Generate packaging instructions
        $packagingInstructions = $this->systems['wms']->generatePackagingInstructions($workOrder);
        
        // Track packaging process
        $this->systems['iot']->trackPackaging($workOrder);
        
        // Update inventory with packaged goods
        $this->systems['wms']->updatePackagedInventory($workOrder);
        
        // Generate shipping documentation
        $this->systems['wms']->generateShippingDocuments($workOrder);
        
        // Ready for shipment
        $workOrder->transitionTo('ready_for_shipment');
    }

    private function handleReadyForShipment($workOrder): void
    {
        // Notify shipping department
        $this->systems['wms']->notifyShipping($workOrder);
        
        // Update ERP with shipment ready status
        $this->systems['erp']->updateShipmentStatus($workOrder, 'ready');
        
        // Generate final documentation
        $this->generateFinalDocumentation($workOrder);
    }

    private function createProductionLog($workOrder, string $event): void
    {
        DB::table('production_logs')->insert([
            'work_order_id' => $workOrder->id,
            'event' => $event,
            'timestamp' => now(),
            'operator_id' => auth()->id(),
            'equipment_data' => json_encode($this->systems['scada']->getCurrentData($workOrder)),
            'environmental_data' => json_encode($this->systems['iot']->getEnvironmentalData()),
        ]);
    }

    private function scheduleProductionMonitoring($workOrder): void
    {
        // Schedule periodic monitoring
        ProductionMonitoringJob::dispatch($workOrder->id)
            ->delay(now()->addMinutes(15));
    }

    private function alertQualityIssues($workOrder, array $qualityData): void
    {
        Log::critical('Quality issues detected in production', [
            'work_order_id' => $workOrder->id,
            'issues' => $qualityData['issues'],
        ]);
        
        // Send alerts to quality team
        QualityAlertJob::dispatch($workOrder, $qualityData)->onQueue('high-priority');
    }

    private function generateProductionReport($workOrder): array
    {
        return [
            'work_order_id' => $workOrder->id,
            'start_time' => $workOrder->production_started_at,
            'end_time' => now(),
            'duration' => now()->diffInMinutes($workOrder->production_started_at),
            'produced_quantity' => $workOrder->produced_quantity,
            'yield_percentage' => ($workOrder->produced_quantity / $workOrder->planned_quantity) * 100,
            'quality_score' => $this->systems['qms']->getQualityScore($workOrder),
            'equipment_utilization' => $this->systems['scada']->getEquipmentUtilization($workOrder),
            'energy_consumption' => $this->systems['iot']->getEnergyConsumption($workOrder),
            'waste_generated' => $this->systems['iot']->getWasteData($workOrder),
        ];
    }

    private function generateFinalDocumentation($workOrder): void
    {
        $documentation = [
            'production_report' => $this->generateProductionReport($workOrder),
            'quality_certificates' => $this->systems['qms']->getQualityCertificates($workOrder),
            'traceability_data' => $this->systems['mes']->getTraceabilityData($workOrder),
            'material_consumption' => $this->systems['wms']->getMaterialConsumption($workOrder),
            'environmental_impact' => $this->systems['iot']->getEnvironmentalImpact($workOrder),
        ];
        
        // Store documentation
        DB::table('production_documentation')->insert([
            'work_order_id' => $workOrder->id,
            'documentation' => json_encode($documentation),
            'generated_at' => now(),
        ]);
    }

    private function handleUnknownProductionState($workOrder, string $state): void
    {
        Log::error('Unknown production state encountered', [
            'work_order_id' => $workOrder->id,
            'state' => $state,
        ]);
        
        // Alert production managers
        ProductionAlertJob::dispatch(
            "Unknown production state: {$state} for work order {$workOrder->id}"
        )->onQueue('alerts');
    }
}

// 4. Integration Orchestrator
class RealWorldIntegrationOrchestrator
{
    private array $integrations;

    public function __construct()
    {
        $this->integrations = [
            'ecommerce' => new EcommerceOrderProcessingIntegration(),
            'healthcare' => new HealthcarePatientJourneyIntegration(),
            'manufacturing' => new ManufacturingProcessIntegration(),
        ];
    }

    public function handleWorkflowEvent(WorkflowTransitioned $event): void
    {
        $subject = $event->getSubject();
        
        // Determine which integration to use based on subject type
        $integrationType = match (get_class($subject)) {
            'App\\Models\\Order' => 'ecommerce',
            'App\\Models\\Patient' => 'healthcare',
            'App\\Models\\WorkOrder' => 'manufacturing',
            default => null,
        };

        if ($integrationType && isset($this->integrations[$integrationType])) {
            try {
                $this->integrations[$integrationType]->handleWorkflowEvent($event);
            } catch (\Exception $e) {
                Log::error('Integration handling failed', [
                    'integration_type' => $integrationType,
                    'subject_id' => $subject->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}

// 5. Usage Examples
class RealWorldUsageExamples
{
    public function demonstrateEcommerceIntegration(): void
    {
        echo "E-commerce Integration Example:\n";
        echo "1. Order placed → Payment processing → Inventory reservation\n";
        echo "2. Payment confirmed → CRM update → Fulfillment start\n";
        echo "3. Order shipped → Tracking generated → Customer notification\n";
        echo "4. Order delivered → Review request → Analytics tracking\n\n";
    }

    public function demonstrateHealthcareIntegration(): void
    {
        echo "Healthcare Integration Example:\n";
        echo "1. Patient registration → EHR creation → Insurance verification\n";
        echo "2. Appointment scheduled → Reminders sent → Pre-visit prep\n";
        echo "3. Check-in → Care team notification → History aggregation\n";
        echo "4. Consultation → Lab/imaging orders → Prescription management\n";
        echo "5. Discharge → Summary generation → Follow-up scheduling\n\n";
    }

    public function demonstrateManufacturingIntegration(): void
    {
        echo "Manufacturing Integration Example:\n";
        echo "1. Production scheduled → Materials reserved → Equipment checked\n";
        echo "2. Production started → IoT monitoring → Quality tracking\n";
        echo "3. In production → Real-time data → Performance monitoring\n";
        echo "4. Quality check → Inspection → Approval/rejection\n";
        echo "5. Completed → Packaging → Shipping preparation\n\n";
    }
}
