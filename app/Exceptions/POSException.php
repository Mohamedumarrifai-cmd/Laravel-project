<?php

namespace App\Exceptions;

use Exception;

class POSException extends Exception {}
class InsufficientStockException extends POSException {}
class ManagerApprovalRequiredException extends POSException {}
class ShiftNotActiveException extends POSException {}
class CreditLimitExceededException extends POSException {}
class PaymentMismatchException extends POSException {}
