' Completely silent VBS wrapper for Task Scheduler
Dim shell, scriptPath, pythonPath, logFile

pythonPath = "C:\Users\natnaelbi\AppData\Local\Programs\Python\Python314\python.exe"
scriptPath = "C:\xampp\htdocs\coursetrack\Script\CombinedAutomation.py"
logFile = "C:\xampp\htdocs\coursetrack\Script\logs\startup_log.txt"

' Create shell object
Set shell = CreateObject("WScript.Shell")

' Run Python script completely hidden (0 = hidden window)
shell.Run """" & pythonPath & """ """ & scriptPath & """", 0, False

' Optional: Log that script was started
Set fso = CreateObject("Scripting.FileSystemObject")
Set log = fso.OpenTextFile(logFile, 8, True)
log.WriteLine Now & " - Automation started via VBS"
log.Close

Set shell = Nothing