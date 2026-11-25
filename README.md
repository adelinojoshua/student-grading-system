
# Student Grading System

## Project Overview

The Student Grading System is a web-based CRUD application designed to manage student academic records and calculate grades automatically.

The system allows an administrator to add, view, update, and delete student records through a responsive web interface.

## Technologies Used

- HTML5
- CSS3
- Bootstrap 5
- JavaScript
- PHP
- MySQL / MariaDB
- XAMPP
- InfinityFree Hosting

## System Features

1. Administrator Login and Logout
2. Student Registration
3. View Student Records
4. Edit Student Information
5. Delete Student Records
6. Automatic Grade Computation
7. Passed and Failed Remarks
8. Dashboard Statistics
9. Student Search and Filtering
10. Subject Performance Insights
11. Export Records to CSV
12. Printable Student Grade Reports
13. Responsive Website Design

## Grading System

The system uses a 1.00 to 5.00 grading scale.

Average = (Prelim + Midterm + Final) / 3

- 1.00 to 3.00 = PASSED
- Above 3.00 = FAILED

Note: The grading scale is configurable and should be verified against the school's official grading policy.

## Database

Database Name: student_grading_system

Tables:
- admin_users
- student_grades

The schema.sql file contains the database structure without private student records or administrator account data.

## Installation

1. Install XAMPP.
2. Start Apache and MySQL.
3. Create a database named student_grading_system.
4. Import schema.sql using phpMyAdmin.
5. Copy the PHP files into the XAMPP htdocs folder.
6. Copy db.example.php to db.php.
7. Configure db.php with the correct database credentials and port.
8. Create an administrator account using a secure provisioning method.
9. Open the website in your browser and sign in.

## Live Website

https://studentgradingportal26.infinityfreeapp.com/

Administrator credentials are not included for security reasons.

## Security

- Administrator authentication
- Password hashing
- Protected administrator dashboard
- Database configuration excluded from GitHub
- No actual student records included in the public repository

## Project Type

Individual Final Project

Course: Web Systems Technologies

Project Title: Student Grading System
