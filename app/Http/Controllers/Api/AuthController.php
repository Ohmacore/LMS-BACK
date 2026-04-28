<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\Teacher;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if ($user->status === 'pending') {
            return response()->json(['message' => 'Your account is pending approval'], 403);
        }

        if ($user->status === 'blocked') {
            return response()->json(['message' => 'Your account has been blocked'], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        // Load relationship based on role
        $userData = $user->toArray();
        if ($user->role === 'teacher') {
            $user->load('teacher');
            $userData['teacher'] = $user->teacher;
        } elseif ($user->role === 'student') {
            $user->load('student');
            $userData['student'] = $user->student;
        }

        return response()->json([
            'user' => $userData,
            'token' => $token,
        ]);
    }

    public function registerStudent(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6',
            'name' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'name' => $request->name ?? explode('@', $request->email)[0],
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'student',
            'status' => 'active',
        ]);

        $student = Student::create([
            'user_id' => $user->id,
            'wallet_balance' => 0,
            'referral_code' => strtoupper(Str::random(8)),
            'referred_by' => $request->referral_code ?
                Student::where('referral_code', $request->referral_code)->first()?->id : null,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'student' => $student,
            'token' => $token,
        ], 201);
    }

    public function registerTeacher(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'email' => 'required|email|unique:users',
            'pseudo' => 'required|string|unique:users',
            'domain_of_interest' => 'required|string',
            'year' => 'required|string',
            'password' => 'required|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'name' => $request->first_name . ' ' . $request->last_name,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'pseudo' => $request->pseudo,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'teacher',
            'status' => 'pending', // Requires admin approval
        ]);

        $teacher = Teacher::create([
            'user_id' => $user->id,
            'domain_of_interest' => $request->domain_of_interest,
            'year' => $request->year,
            'rating' => 0,
            'total_students' => 0,
        ]);

        return response()->json([
            'message' => 'Registration successful. Your account is pending approval by administration.',
            'user' => $user,
            'teacher' => $teacher,
        ], 201);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'teacher') {
            $user->load('teacher');
        } elseif ($user->role === 'student') {
            $user->load('student');
        }

        return response()->json(['user' => $user]);
    }
}
