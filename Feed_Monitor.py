#!/usr/bin/env python
# encoding: utf-8
"""
Feed_Monitor.py

Created by Alden Turner on 2010-08-22.
"""

import os
from os import path
import feedparser
from xml.etree.ElementTree import ElementTree, Element
from time import sleep, mktime, strftime
from datetime import datetime, timedelta
import smtplib
from email.mime.text import MIMEText

class Feed_Monitor:
	'''Reads a configuration XML file, gathers information from RSS feeds, and creates reports from the data gathered.'''
	feeds = []
	parsed_feeds = []
	most_recent = {}
	output = ""
	run_time = 0
	sleep_time = 0
	config_path = ''
	old_feeds_path = ''
	output_path = ''
	emails = []
	
	def __init__(self, r_time=600, s_time=120, c_path='', o_f_path=''):
		'''Initization of Feed_Monitor
			
		r_time: how long the main loop will run in seconds. Default is 1 hour.
		s_time: the time between checking the feeds in seconds. Default is 2 minutes.
		c_path: the path to the configuration xml file
		o_f_path: the path where the old feed information is located'''
		
		#initialize local storage
		self.feeds = []
		self.parsed_feeds = []
		self.most_recent = {}
		self.output = ""
		self.run_time = r_time
		self.sleep_time = s_time
		self.emails = []
		
		#set default paths if none were specified (depends on the OS)
		if os.name == 'nt' and (c_path == '' and o_f_path==''):
			self.config_path = "C:\\Program Files\\Feed Monitor\\config.xml"
			self.old_feeds_path = "C:\\Program Files\\Feed Monitor\\old_feeds.xml"
		else:
			self.config_path = "config.xml"
			self.old_feeds_path = "old_feeds.xml"
		  
		#check if the config file exists
		if path.exists(self.config_path):
			#gather information for feeds (and possibly sleep_time)
			self.ReadFeedXML(self.config_path)
		else:
			#file does not exist; print error, end program
			print 'Error: Configuration XML at ' + self.config_path + ' does not exist.\n Exiting Feed Monitor.'
			return
		
		#Make sure that feeds were taken out of the config file, if none were, end program
		if len(self.feeds) == 0:
			print 'No feeds were retrieved from ' + self.config_path + '.\n Exiting Feed Monitor.'
			return
		
		#check for existence of the internal XML config file
		if path.exists(self.old_feeds_path):
			#if the interal XML config file exists, fill most_recent
			#index is the feed address, definition is a datetime
			self.ReadOldFeedsXML(self.old_feeds_path)
		
		#Sets the time that the program should stop checking the feeds
		#Determined by add run_time to the current time
		end_time = datetime.now() + timedelta(seconds=self.run_time)
		
		while datetime.now() < end_time:
			#get the RSS feeds
			self.GetAllRSS()
			
			#remove entries that have been previously reported
			self.CullOldEntries()
			
			#output entries
			self.DeliverResults()
			
			#reset variables that will be used again
			self.parsed_feeds = []
			self.output = ""
			
			#wait for sleep_time before restarting
			sleep(self.sleep_time)	
		
		#export most_recent to old_feeds_path for the next time that Feed_Monitor is used
		self.WriteOldFeedsXML(self.old_feeds_path)


	def ReadFeedXML(self, path):
		'''Reads from the XML file at the given path parsing the rss urls into self.feeds.'''
		#setup ElementTree that the XML will be parsed into
		tree = ElementTree()
		#parse the XML
		tree.parse(path)
		
		#gather each rss_feed tag
		rss_feeds = tree.findall("rss_feed")
		#pull url attributes for each rss_feed tag
		for i in rss_feeds:
			self.feeds.append(i.attrib["url"])
			
		#try to gather a time tag
		times = tree.findall("time")
		#if one was found
		for i in times:
			#if it had a new value for the total run time, set it
			if i.attrib["run"]:
				self.run_time = float(i.attrib["run"])
			#if it had a new value for the sleep time, set it
			if i.attrib["sleep"]:
				self.sleep_time = float(i.attrib["sleep"])
	

	def ReadOldFeedsXML(self, path):
		'''Reads from the XML file at the given path parsing the rss urls and time attributes into a dictionary.
			The dictionary uses the url as a key and a datetime value as the definition.'''
		#setup ElementTree that the XML will be parsed into
		tree = ElementTree()
		#parse the XML
		tree.parse(path)
		#gather each rss_feed tag
		rss_feeds = tree.findall("rss_feed")
		#pull url and datetime attributes from each rss_feed tag and use as key : value pairs
		for i in rss_feeds:
			self.most_recent[i.attrib["url"]] = datetime(int(i.attrib["year"]), int(i.attrib["month"]), int(i.attrib["day"]), int(i.attrib["hour"]), int(i.attrib["min"]), int(i.attrib["sec"]))
	

	def GetAllRSS(self):
		'''Uses the feedparser library to pull the information from each address in self.feeds into self.parsed_feeds.'''
		for i in self.feeds:
			self.parsed_feeds.append(feedparser.parse(i))
	

	def CullOldEntries(self):
		'''Removes previously seen feed entries from self.parsed_feeds and updates self.most_recent with the last update time for each feed'''
		#check if most_recent is empty, if it is, no reason to check anything else
		if len(self.most_recent) > 0:
			#for each feed
			for i in self.parsed_feeds:
				#check if it is in most_recent (i.e. has been pulled before)
				if i.feed.link.encode('utf-8') in self.most_recent:
					#if the update time of the feed has changed
					if self.most_recent[i.feed.link.encode('utf-8')] < datetime.fromtimestamp(mktime(i.entries[0].updated_parsed)):
						#check that each entry is has not been seen before
						for j in i.entries:
							if self.most_recent[i.feed.link.encode('utf-8')] >= datetime.fromtimestamp(mktime(j.updated_parsed)):
								#if an entry has been seen before, remove it
								del j
					else:
						#all entries are old, removing them all
						i.entries = []
		#update most_recent to reflect the time of the new feeds
		for i in self.parsed_feeds:
			#checks for local 
			if len(i.entries) > 0:
				self.most_recent[i.feed.link.encode('utf-8')] = datetime.fromtimestamp(mktime(i.entries[0].updated_parsed))
	

	def GenerateMessageString(self):
		'''Add the gathered feed entries to the string self.output'''
		
		self.output += 'New entries for ' + strftime('%X %x %Z') + '\n'
		
		#make a header with feed title, description, and number of new entries
		for x in self.parsed_feeds:
			self.output += x.feed.title.encode('utf-8') + "\n"
			self.output += x.feed.description.encode('utf-8') + "\n"
			self.output += str(len(x.entries)) + " new entries.\n\n"
			
			#add the feed's new entries to the output string
			for y in x.entries:
				self.output += y.title.encode('utf-8') + "\n"
				self.output += y.date.encode('utf-8') + "\n"
				self.output += y.description.encode('utf-8') + "\n"
				self.output += y.link.encode('utf-8') + "\n"
			self.output += "\n\n\n"
	
		
	def WriteOldFeedsXML(self, path):
		'''Writes the dictionary self.most_recent to a XML file'''
		
		#create root element
		ele = Element('FeedHist')
		
		#get lists of urls and last update times from self.most_recent
		urls = list(self.most_recent.iterkeys())
		dates = list(self.most_recent.itervalues())
		
		#counting loop variable
		count = 0
		#list of dictionary elements that will become element attributes
		diclist = []
		while count < len(urls):
			#initalize/reset the dictionary
			curdic = {}
			
			#add each variable/attribute to the dictionary
			curdic['url'] = urls[count]
			curdic['year'] = str(dates[count].year)
			curdic['month'] = str(dates[count].month)
			curdic['day'] = str(dates[count].day)
			curdic['hour'] = str(dates[count].hour)
			curdic['min'] = str(dates[count].minute)
			curdic['sec'] = str(dates[count].second)
			
			#append this dictionary to the list of dictionaries
			diclist.append(curdic)
			#increment count
			count += 1
			
		#add all the dictionarys in diclist as attributes of rss_feed subelements of ele
		for i in diclist:	
			newele = Element('rss_feed', i)
			ele.append(newele)
		
		#Write the tree to the path
		#Will overwrite previous versions of itself
		ElementTree(ele).write(path)
	
	def DeliverResults(self):
		'''Outputs to all sources'''
		
		#check that if there are new entries
		total = 0
		for i in self.parsed_feeds:
			total += len(i.entries)
		
		#if there are new entries, deliver them
		if total > 0:
			#generate the string that will be sent
			self.GenerateMessageString()
		
			#Print the new entries to the terminal
			print self.output
		
			if len(self.emails) > 0:
				#Email new entries to the addresses in self.emails
				for i in self.emails:
					print "Emailing to" + i + "\n"
					self.sendMessage()
		else:
			#Inform the user that there are no new entries
			print 'No new entries as of '+ strftime('%X %x %Z') + '. No emails will be sent.'

	
	def sendMessage(self):
		'''Emails the new entries to the address in self.emails'''
		
		#set message body as the output text
		msg = MIMEText(self.output)
		#set sender
		sender = 'alonzopt@gmail.com'
		#set subject
		msg['Subject'] = 'RSS Feed Updates'
		
		#establish SMTP connection
		s = smtplib.SMTP()
		s.connect()
		
		#for each recipent, set the 'To' field and send
		for i in self.emails:
			msg['To'] = i
			s.sendmail(sender, [i], msg.as_string())
		#close the connection
		s.quit()
	

if __name__ == '__main__': 
	#import sys, will use if implementing command line args
	Feed_Monitor()
	