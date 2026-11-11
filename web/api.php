<?php

namespace MRBS;

require 'defaultincludes.inc';
require_once 'mrbs_sql.inc';

// Phase 1: check authentication and authorization.
$secret_token = getenv('MRBS_API_SECRET_TOKEN');
if (!$secret_token) {
    http_response_code(500);
    write_response_json(['error' => 'API secret token not set']);
    exit;
}

// Check Authorization header
$headers = getallheaders();
$auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? null;
$expected = 'Bearer ' . $secret_token;
if (!is_string($auth_header) || !hash_equals($expected, $auth_header)) {
    http_response_code(401);
    write_response_json(['error' => 'Unauthorized']);
    exit;
}

// Phase 2: Parse the request
switch ($_GET['q'] ?? '') {
    case 'query':
        // Query available rooms in a given time slot. 
        handle_query();
        break;

    case 'book':
        // Book a room for a given time slot.
        handle_book();
        break;

    case 'details':
        // Get details of a booking.
        handle_details();
        break;

    case 'delete':
        // Delete a booking.
        handle_delete();
        break;

    default:
        // Invalid request.
        http_response_code(400);
        write_response_json(['error' => 'Invalid request']);
        exit;
}

function write_response_json($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
}

function handle_query() {
    $start_time = filter_var($_GET['start_time'] ?? null, FILTER_VALIDATE_INT);
    $end_time = filter_var($_GET['end_time'] ?? null, FILTER_VALIDATE_INT);

    if ($start_time === false || $end_time === false || $start_time < 0 || $end_time <= $start_time) {
        http_response_code(400);
        write_response_json(['error' => 'Invalid start_time or end_time']);
        exit;
    }

    // Obtain the list of all available rooms
    $sql = "SELECT id, area_id, room_name, capacity FROM " . _tbl('room') . " WHERE disabled=0;";
    $res = db()->query($sql);
    if (! $res) {
        http_response_code(500);
        write_response_json(['error' => 'Database query failed']);
        exit;
    }
    else {
        $rooms = [];
        while (false !== ($row = $res->next_row_keyed())) {
            $rooms[] = [
                'id' => (int)$row['id'],
                'area_id' => (int)$row['area_id'],
                'name' => $row['room_name'],
                'capacity' => (int)$row['capacity'],
            ];
        }
    }

    $sql = "SELECT DISTINCT E.room_id FROM " . _tbl('entry') . " E, " . _tbl('room') . " R
           WHERE E.room_id=R.id
             AND start_time < ?
             AND end_time > ?;";
    $sql_params = [$end_time, $start_time];
    $res = db()->query($sql, $sql_params);

    $booked_ids = [];
    if ($res) {
        while (false !== ($row = $res->next_row_keyed())) {
            $booked_ids[] = $row['room_id'];
        }
    }
    else {
        http_response_code(500);
        write_response_json(['error' => 'Database query failed']);
        exit;
    }
    
    // Remove all rooms that have a booking
    $rooms = array_filter($rooms, function($room) use ($booked_ids) {
        return !in_array($room['id'], $booked_ids);
    });

    $rooms = array_values($rooms);

    write_response_json($rooms);
    exit;
}

function handle_book() {
    // Only allow POST requests for booking.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        write_response_json(['error' => 'Method not allowed']);
        exit;
    }

    // Parse the booking from the POST JSON data.
    $booking = json_decode(file_get_contents('php://input'), true);

    $required_fields = ['room_id', 'start_time', 'end_time', 'name'];

    // Validate that all required fields are present.
    if (!is_array($booking) || count(array_intersect_key(array_flip($required_fields), $booking)) !== count($required_fields)) {
        http_response_code(400);
        write_response_json(['error' => 'Missing required booking parameters']);
        exit;
    }

    foreach (['room_id', 'start_time', 'end_time'] as $field) {
        $booking[$field] = filter_var($booking[$field], FILTER_VALIDATE_INT);
        if ($booking[$field] === false || $booking[$field] < ($field === 'room_id' ? 1 : 0)) {
            http_response_code(400);
            write_response_json(['error' => 'Invalid booking parameters']);
            exit;
        }
    }
    if ($booking['end_time'] <= $booking['start_time'] ||
        !is_string($booking['name']) || trim($booking['name']) === '' ||
        (isset($booking['description']) && !is_string($booking['description']))) {
        http_response_code(400);
        write_response_json(['error' => 'Invalid booking parameters']);
        exit;
    }

    // Only accept supported fields; internal MRBS fields are controlled by the API.
    $booking = array_intersect_key($booking, array_flip([
        'room_id', 'start_time', 'end_time', 'name', 'description'
    ]));
    $booking['description'] = $booking['description'] ?? '';

    $booking['type'] = 'I'; // Internal booking are the only type supported by the API.
    $booking['create_by'] = 'api-user';
    $repeat_type = RepeatRule::NONE;
    $repeat_interval = 1;
    $repeat_end_time = null;
    $booking['repeat_rule'] = new RepeatRule();
    $booking['repeat_rule']->setType($repeat_type);
    $booking['repeat_rule']->setInterval($repeat_interval);
    $booking['repeat_rule']->setEndDate($repeat_end_time);

    // Check the area in which the room is
    $sql = "SELECT A.approval_enabled FROM " . _tbl('area') . " A INNER JOIN " . _tbl('room') . " R ON R.area_id=A.id WHERE R.id=? AND R.disabled=0";
    $sql_params = [$booking["room_id"]];
    $res = db()->query($sql, $sql_params);

    if ($res) {
       $row = $res->next_row_keyed();
       if (!$row) {
           http_response_code(404);
           write_response_json(['error' => 'Room not found or disabled']);
           exit;
       }
       $approval_enabled = (bool)$row["approval_enabled"];
    }
    else {
        http_response_code(500);
        write_response_json(['error' => 'Could not find area for the specified room']);
        exit;
    }

    $booking["awaiting_approval"] = $approval_enabled;

    $res = mrbsMakeBookings([ $booking ]);

    if (!empty($res['new_details'][0]['id'])) {
        // Successfully booked.
        write_response_json([
            'status' => 'success',
            'booking' => $res['new_details'][0],
        ]);
    }
    else {
        // Booking failed.
        http_response_code(500);
        write_response_json([
            'error' => 'Booking failed',
            'violations' => $res['violations'] ?? [],
            'conflicts' => $res['conflicts'] ?? [],
        ]);
        exit;
    }
}

function handle_delete() {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        http_response_code(405);
        write_response_json(['error' => 'Method not allowed']);
        exit;
    }

    $entry_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    // Check if the user is the API user: if not, reject the request.
    if (!$entry_id) {
        http_response_code(400);
        write_response_json(['error' => 'Invalid booking id']);
        exit;
    }

    $entry = get_entry_by_id($entry_id);
    if (! $entry) {
        http_response_code(404);
        write_response_json(['error' => 'Booking not found']);
        exit;
    }

    if ($entry['create_by'] !== 'api-user') {
        http_response_code(403);
        write_response_json(['error' => 'Forbidden: cannot delete this booking']);
        exit;
    }

    // FIXME: In principle, we may have bookings that are part of a series. In
    // that case, we should decide whether to delete just one entry or the
    // whole series. For now, we just delete the single entry.
    $sql = "DELETE FROM " . _tbl('entry') . " WHERE id=?";
    if (db()->command($sql, array($entry_id)) > 0) {
        write_response_json(['status' => 'success']);

    }
    else  {
        http_response_code(500);
        write_response_json(['error' => 'Failed to delete booking']);
    }
}

function handle_details() {
    $entry_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if (!$entry_id) {
        http_response_code(400);
        write_response_json(['error' => 'Invalid booking id']);
        exit;
    }

    $entry = get_entry_by_id($entry_id);
    if (! $entry) {
        http_response_code(404);
        write_response_json(['error' => 'Booking not found']);
        exit;
    }
    
    write_response_json($entry);
}
